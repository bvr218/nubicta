<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\DianSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CertificateService
{
    public const DIR = 'dian/certificados';

    /** Valida el P12 y devuelve sus metadatos. Lanza RuntimeException si no se puede abrir. */
    public function inspect(string $contents, string $password, string $expectedNit): array
    {
        $bundle = $this->read($contents, $password);
        $x509 = openssl_x509_parse($bundle['cert'], false);
        if (! is_array($x509)) {
            throw new RuntimeException('No fue posible leer los datos X.509 del certificado.');
        }
        $key = openssl_pkey_get_private($bundle['pkey']);
        if ($key === false || ! openssl_x509_check_private_key($bundle['cert'], $key)) {
            throw new RuntimeException('La clave privada no corresponde al certificado.');
        }

        $subject = $this->flatten($x509['subject'] ?? []);
        $issuer = $this->flatten($x509['issuer'] ?? []);
        $validFrom = (int) ($x509['validFrom_time_t'] ?? 0);
        $validTo = (int) ($x509['validTo_time_t'] ?? 0);
        $nit = preg_replace('/\D+/', '', $expectedNit) ?? '';

        $warnings = [];
        if ($validTo <= time()) {
            $warnings[] = 'El certificado está vencido.';
        } elseif ($validFrom > time()) {
            $warnings[] = 'El certificado todavía no está vigente.';
        }
        if ($nit === '' || ! str_contains(preg_replace('/\D+/', '', $subject) ?? '', $nit)) {
            $warnings[] = 'El NIT del certificado no coincide con el NIT de la empresa.';
        }
        if (! preg_match('/CAMERFIRMA|CERTICAMARA|GSE|ANDES|THOMAS SIGNE|OlimpiaIT|DIAN/i', $issuer)) {
            $warnings[] = 'El emisor no es una entidad de certificación reconocida por la DIAN.';
        }

        return [
            'subject' => $subject,
            'common_name' => $x509['subject']['commonName'] ?? null,
            'issuer' => $issuer,
            'issuer_name' => $x509['issuer']['organizationName'] ?? ($x509['issuer']['commonName'] ?? null),
            'serial_number' => (string) ($x509['serialNumberHex'] ?? $x509['serialNumber'] ?? ''),
            'fingerprint_sha256' => Str::lower((string) openssl_x509_fingerprint($bundle['cert'], 'sha256')),
            'valid_from' => date(DATE_ATOM, $validFrom),
            'valid_to' => date(DATE_ATOM, $validTo),
            'warnings' => $warnings,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function store(DianSetting $settings, string $contents, string $password, string $originalName, Company $company): array
    {
        $meta = $this->inspect($contents, $password, $company->nit);
        $meta['file_name'] = $originalName;

        $path = self::DIR.'/'.$company->id.'/'.Str::uuid().'.p12.enc';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($contents)));

        $previous = $settings->certificate_path;
        $settings->update(['certificate_path' => $path, 'certificate_password' => $password, 'certificate_meta' => $meta]);
        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return $meta;
    }

    /** @return array{cert:string,pkey:string} */
    public function load(DianSetting $settings): array
    {
        if (! $settings->hasCertificate()) {
            throw new RuntimeException('No hay certificado digital cargado.');
        }
        $contents = base64_decode(Crypt::decryptString(Storage::disk('local')->get($settings->certificate_path)));

        return $this->read($contents, (string) $settings->certificate_password);
    }

    /** Certificado autofirmado para probar el flujo en modo simulado. */
    public function makeTestCertificate(Company $company, string $password): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $dn = [
            'countryName' => 'CO',
            'organizationName' => 'CERTIFICADO DE PRUEBA DIAN',
            'commonName' => Str::limit($company->business_name ?: 'Empresa', 60, ''),
            'serialNumber' => $company->nit,
        ];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], random_int(1000, 999999));
        openssl_pkcs12_export($cert, $p12, $key, $password);

        return $p12;
    }

    /** @return array{cert:string,pkey:string} */
    private function read(string $contents, string $password): array
    {
        $bundle = [];
        if (! @openssl_pkcs12_read($contents, $bundle, $password) && ! $this->readLegacy($contents, $password, $bundle)) {
            throw new RuntimeException('No se pudo abrir el certificado: la contraseña no es correcta o el archivo no es un PKCS12 (.p12 / .pfx) válido.');
        }
        while (openssl_error_string() !== false) {
        }
        if (blank($bundle['cert'] ?? null) || blank($bundle['pkey'] ?? null)) {
            throw new RuntimeException('El archivo debe contener el certificado y su clave privada.');
        }

        return ['cert' => $bundle['cert'], 'pkey' => $bundle['pkey']];
    }

    /** OpenSSL 3 no abre P12 con cifrados heredados; se usa el binario con -legacy (contraseña por stdin). */
    private function readLegacy(string $contents, string $password, array &$bundle): bool
    {
        $bin = '/usr/bin/openssl';
        if (! function_exists('proc_open') || ! is_executable($bin)) {
            return false;
        }
        $process = proc_open([$bin, 'pkcs12', '-legacy', '-in', '/dev/fd/3', '-clcerts', '-nodes', '-passin', 'fd:4'], [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r'], 4 => ['pipe', 'r'],
        ], $pipes);
        if (! is_resource($process)) {
            return false;
        }
        fwrite($pipes[3], $contents);
        fclose($pipes[3]);
        fwrite($pipes[4], $password.PHP_EOL);
        fclose($pipes[4]);
        $pem = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0
            || ! preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $c)
            || ! preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.*?-----END (?:RSA |EC )?PRIVATE KEY-----/s', $pem, $k)) {
            return false;
        }
        $bundle = ['cert' => $c[0], 'pkey' => $k[0]];

        return true;
    }

    private function flatten(mixed $value): string
    {
        if (! is_array($value)) {
            return trim((string) $value);
        }
        $parts = [];
        foreach ($value as $k => $v) {
            $v = $this->flatten($v);
            if ($v !== '') {
                $parts[] = is_string($k) ? "$k=$v" : $v;
            }
        }

        return implode(', ', $parts);
    }
}
