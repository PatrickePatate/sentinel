<?php

namespace App\Ssh\Provisioning;

use RuntimeException;

/**
 * Signs client bundles in the OpenSSH "SSHSIG" format, so a machine can check them with nothing but `ssh-keygen -Y verify`.
 * The public key is baked into the root-owned updater at provisioning: holding the machine's SSH key (or a database
 * dump) is not enough to get a bundle installed.
 */
class BundleSigner
{
    public const NAMESPACE = 'sentinel-bundle';

    public const PRINCIPAL = 'sentinel';

    private ?string $secretKey = null;

    /** The armored signature of $message, as `ssh-keygen -Y sign` would print it. */
    public function sign(string $message): string
    {
        $signed = 'SSHSIG'.$this->string(self::NAMESPACE).$this->string('').$this->string('sha512').$this->string(hash('sha512', $message, true));
        $signature = sodium_crypto_sign_detached($signed, $this->secretKey());

        $blob = 'SSHSIG'.pack('N', 1).$this->string($this->publicKeyBlob()).$this->string(self::NAMESPACE).$this->string('')
            .$this->string('sha512').$this->string($this->string('ssh-ed25519').$this->string($signature));

        return "-----BEGIN SSH SIGNATURE-----\n".chunk_split(base64_encode($blob), 70, "\n")."-----END SSH SIGNATURE-----\n";
    }

    /** The line of the updater's allowed_signers file. */
    public function allowedSigner(): string
    {
        return self::PRINCIPAL.' namespaces="'.self::NAMESPACE.'" ssh-ed25519 '.base64_encode($this->publicKeyBlob());
    }

    private function publicKeyBlob(): string
    {
        return $this->string('ssh-ed25519').$this->string(sodium_crypto_sign_publickey_from_secretkey($this->secretKey()));
    }

    private function secretKey(): string
    {
        if ($this->secretKey !== null) {
            return $this->secretKey;
        }

        $path = config('sentinel.actions.bundle_signing_key');
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($path)) {
            $this->generate($path);
        }

        $key = base64_decode(trim((string) file_get_contents($path)), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException("The bundle signing key at {$path} is not valid.");
        }

        return $this->secretKey = $key;
    }

    private function generate(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $tmp = tempnam(dirname($path), '.signing');
        chmod($tmp, 0600);
        file_put_contents($tmp, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))."\n");

        // link() fails if the key exists: two workers racing on first use still end up with the same key.
        @link($tmp, $path);
        unlink($tmp);
    }

    private function string(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
