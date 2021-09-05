<?php declare(strict_types=1);

namespace WeChatPay\Crypto;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_PKCS1_OAEP_PADDING;
use const PHP_URL_SCHEME;

use function array_column;
use function array_combine;
use function array_keys;
use function base64_decode;
use function base64_encode;
use function gettype;
use function is_array;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function ltrim;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_private_decrypt;
use function openssl_public_encrypt;
use function openssl_sign;
use function openssl_verify;
use function pack;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function wordwrap;

use UnexpectedValueException;

/**
 * Provides some methods for the RSA `OPENSSL_ALGO_SHA256` with `OPENSSL_PKCS1_OAEP_PADDING`.
 */
class Rsa
{
    /** asymmetric public key type string */
    public const KEY_TYPE_PUBLIC = 'public';
    /** asymmetric private key type string */
    public const KEY_TYPE_PRIVATE = 'private';

    private const LOCAL_FILE_PROTOCOL = 'file://';
    private const PKEY_NEEDLE = ' KEY-';
    private const PKEY_FORMAT = "-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----";
    private const PKEY_PEM_FORMAT_PATTERN = '#-{5}BEGIN ((?:RSA )?(?:PUBLIC|PRIVATE)) KEY-{5}\r?\n([^-]+)\r?\n-{5}END \1 KEY-{5}#';
    private const CHR_CR = "\r";
    private const CHR_LF = "\n";

    /** @var array<string,array{string,string,int}> - Supported loading rules */
    private const RULES = [
        'private.pkcs1' => [self::PKEY_FORMAT, 'RSA PRIVATE', 16],
        'private.pkcs8' => [self::PKEY_FORMAT, 'PRIVATE',     16],
        'public.pkcs1'  => [self::PKEY_FORMAT, 'RSA PUBLIC',  15],
        'public.spki'   => [self::PKEY_FORMAT, 'PUBLIC',      14],
    ];

    /**
     * @var string - Equal to `sequence(oid(1.2.840.113549.1.1.1), null))`
     * @link https://datatracker.ietf.org/doc/html/rfc3447#appendix-A.2
     */
    private const ASN1_OID_RSAENCRYPTION = '300d06092a864886f70d0101010500';
    private const ASN1_SEQUENCE = 48;
    private const CHR_NUL = "\0";
    private const CHR_ETX = "\3";

    /**
     * Translate the \$thing strlen from `X690` style to the `ASN.1` 128bit hexadecimal length string
     *
     * @param string $thing - The string
     *
     * @return string The `ASN.1` 128bit hexadecimal length string
     */
    private static function encodeLength(string $thing): string
    {
        $num = strlen($thing);
        if ($num <= 0x7F) {
            return sprintf('%c', $num);
        }

        $tmp = ltrim(pack('N', $num), self::CHR_NUL);
        return pack('Ca*', strlen($tmp) | 0x80, $tmp);
    }

    /**
     * Convert the `PKCS#1` format RSA Public Key to `SPKI` format
     *
     * @param string $thing - The base64-encoded string, without evelope style
     *
     * @return string The `SPKI` style public key without evelope string
     */
    public static function pkcs1ToSpki(string $thing): string
    {
        $raw = self::CHR_NUL . base64_decode($thing);
        $new = pack('H*', self::ASN1_OID_RSAENCRYPTION) . self::CHR_ETX . self::encodeLength($raw) . $raw;

        return base64_encode(pack('Ca*a*', self::ASN1_SEQUENCE, self::encodeLength($new), $new));
    }

    /**
     * Sugar for loading input `privateKey` string, pure `base64-encoded-string` without LF and evelope.
     *
     * @param string $thing - The string in `PKCS#8` format.
     * @return \OpenSSLAsymmetricKey|resource|mixed
     * @throws UnexpectedValueException
     */
    public static function fromPkcs8(string $thing)
    {
        $pkey = openssl_pkey_get_private(static::parse(sprintf('private.pkcs8://%s', $thing)));

        if (false === $pkey) {
            throw new UnexpectedValueException(sprintf('Cannot load the PKCS#8 privateKey(%s).', $thing));
        }

        return $pkey;
    }

    /**
     * Sugar for loading input `privateKey/publicKey` string, pure `base64-encoded-string` without LF and evelope.
     *
     * @param string $thing - The string in `PKCS#1` format.
     * @param string $type - Either `self::KEY_TYPE_PUBLIC` or `self::KEY_TYPE_PRIVATE` string, default is `self::KEY_TYPE_PRIVATE`.
     * @return \OpenSSLAsymmetricKey|resource|mixed
     * @throws UnexpectedValueException
     */
    public static function fromPkcs1(string $thing, string $type = self::KEY_TYPE_PRIVATE)
    {
        $pkey = ($isPublic = $type === static::KEY_TYPE_PUBLIC)
            ? openssl_pkey_get_public(static::parse(sprintf('public.pkcs1://%s', $thing), $type))
            : openssl_pkey_get_private(static::parse(sprintf('private.pkcs1://%s', $thing)));

        if (false === $pkey) {
            throw new UnexpectedValueException(sprintf('Cannot load the PKCS#1 %s(%s).', $isPublic ? 'publicKey' : 'privateKey', $thing));
        }

        return $pkey;
    }

    /**
     * Sugar for loading input `publicKey` string, pure `base64-encoded-string` without LF and evelope.
     *
     * @param string $thing - The string in `SKPI` format.
     * @return \OpenSSLAsymmetricKey|resource|mixed
     * @throws UnexpectedValueException
     */
    public static function fromSpki(string $thing)
    {
        $pkey = openssl_pkey_get_public(static::parse(sprintf('public.spki://%s', $thing), static::KEY_TYPE_PUBLIC));

        if (false === $pkey) {
            throw new UnexpectedValueException(sprintf('Cannot load the SPKI publicKey(%s).', $thing));
        }

        return $pkey;
    }

    /**
     * Loading the privateKey/publicKey.
     *
     * The `\$thing` can be one of the following:
     * - `file://` protocol `PKCS#1/PKCS#8 privateKey`/`SPKI publicKey`/`x509 certificate(for publicKey)` string.
     * - `public.spki://`, `public.pkcs1://`, `private.pkcs1://`, `private.pkcs8://` protocols string.
     * - full `PEM` in `PKCS#1/PKCS#8` format `privateKey`/`publicKey`/`x509 certificate(for publicKey)` string.
     * - `\OpenSSLAsymmetricKey` (PHP8) or `resource#pkey` (PHP7).
     * - `\OpenSSLCertificate` (PHP8) or `resource#X509` (PHP7) for publicKey.
     * - `Array` of `[privateKeyString,passphrase]` for encrypted privateKey.
     *
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|array{string,string}|string|mixed $thing - The thing.
     * @param string $type - Either `self::KEY_TYPE_PUBLIC` or `self::KEY_TYPE_PRIVATE` string, default is `self::KEY_TYPE_PRIVATE`.
     *
     * @return \OpenSSLAsymmetricKey|resource|mixed
     * @throws UnexpectedValueException
     */
    public static function from($thing, string $type = self::KEY_TYPE_PRIVATE)
    {
        $pkey = ($isPublic = $type === static::KEY_TYPE_PUBLIC)
            ? openssl_pkey_get_public(static::parse($thing, $type))
            : openssl_pkey_get_private(static::parse($thing));

        if (false === $pkey) {
            throw new UnexpectedValueException(sprintf(
                'Cannot load %s from(%s).',
                $isPublic ? 'publicKey' : 'privateKey',
                is_string($thing) ? $thing : gettype($thing)
            ));
        }

        return $pkey;
    }

    /**
     * Parse the `\$thing` for the `openssl_pkey_get_public`/`openssl_pkey_get_private` function.
     *
     * The `\$thing` can be the `file://` protocol privateKey/publicKey string, eg:
     *   - `file:///my/path/to/private.pkcs1.key`
     *   - `file:///my/path/to/private.pkcs8.key`
     *   - `file:///my/path/to/public.spki.pem`
     *   - `file:///my/path/to/x509.crt` (for publicKey)
     *
     * The `\$thing` can be the `public.spki://`, `public.pkcs1://`, `private.pkcs1://`, `private.pkcs8://` protocols string, eg:
     *   - `public.spki://MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCg...`
     *   - `public.pkcs1://MIIBCgKCAQEAgYxTW5Yj...`
     *   - `private.pkcs1://MIIEpAIBAAKCAQEApdXuft3as2x...`
     *   - `private.pkcs8://MIIEpAIBAAKCAQEApdXuft3as2x...`
     *
     * The `\$thing` can be the string with PEM `evelope`, eg:
     *   - `-----BEGIN RSA PRIVATE KEY-----...-----END RSA PRIVATE KEY-----`
     *   - `-----BEGIN PRIVATE KEY-----...-----END PRIVATE KEY-----`
     *   - `-----BEGIN RSA PUBLIC KEY-----...-----END RSA PUBLIC KEY-----`
     *   - `-----BEGIN PUBLIC KEY-----...-----END PUBLIC KEY-----`
     *   - `-----BEGIN CERTIFICATE-----...-----END CERTIFICATE-----` (for publicKey)
     *
     * The `\$thing` can be the \OpenSSLAsymmetricKey/\OpenSSLCertificate/resouce, eg:
     *   - `\OpenSSLAsymmetricKey` (PHP8) or `resource#pkey` (PHP7) for publicKey/privateKey.
     *   - `\OpenSSLCertificate` (PHP8) or `resource#X509` (PHP7) for publicKey.
     *
     * The `\$thing` can be the Array{$privateKey,$passphrase} style for loading privateKey, eg:
     *   - [`file:///my/path/to/encrypted.private.pkcs8.key`, 'your_pass_phrase']
     *   - [`-----BEGIN ENCRYPTED PRIVATE KEY-----...-----END ENCRYPTED PRIVATE KEY-----`, 'your_pass_phrase']
     *
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|array{string,string}|string|mixed $thing - The thing.
     * @param string $type - Either `self::KEY_TYPE_PUBLIC` or `self::KEY_TYPE_PRIVATE` string, default is `self::KEY_TYPE_PRIVATE`.
     * @return \OpenSSLAsymmetricKey|resource|array{string,string}|string|mixed
     */
    private static function parse($thing, string $type = self::KEY_TYPE_PRIVATE)
    {
        $src = $thing;

        if (is_resource($src) || is_object($src) || is_array($src) || is_int(strpos($src, self::LOCAL_FILE_PROTOCOL))) {
            return $src;
        }

        if (is_int(strpos($src, '://'))) {
            $protocol = parse_url($src, PHP_URL_SCHEME);
            [$format, $kind, $offset] = static::RULES[$protocol] ?? [null, null, null];
            if ($format && $kind && $offset) {
                $src = substr($src, $offset);
                if ('public.pkcs1' === $protocol) {
                    $src = static::pkcs1ToSpki($src);
                    [$format, $kind] = static::RULES['public.spki'];
                }
                return sprintf($format, $kind, wordwrap($src, 64, self::CHR_LF, true));
            }
        }

        if (is_int(strpos($src, self::PKEY_NEEDLE))) {
            if ($type === self::KEY_TYPE_PUBLIC && preg_match(self::PKEY_PEM_FORMAT_PATTERN, $src, $matches)) {
                [, $kind, $base64] = $matches;
                $mapRules = (array)array_combine(array_column(self::RULES, 1/*column*/), array_keys(self::RULES));
                $protocol = $mapRules[$kind] ?? '';
                if ('public.pkcs1' === $protocol) {
                    return self::parse(sprintf('%s://%s', $protocol, str_replace([self::CHR_CR, self::CHR_LF], '', $base64)), $type);
                }
            }
            return $src;
        }

        return $src;
    }

    /**
     * Encrypts text by the given `$publicKey` in the `$padding`(default is `OPENSSL_PKCS1_OAEP_PADDING`) mode.
     *
     * Some of APIv2 were required the `$padding` mode as of `RSAES-PKCS1-v1_5` which is equal to the `OPENSSL_PKCS1_PADDING` constant, exposed it for this case.
     *
     * **Warning:** While the `$padding` is `OPENSSL_NO_PADDING` value, the `$plaintext` must be padded by `caller-self`, be careful about this.
     *
     * @param string $plaintext - Cleartext to encode.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|object|resource|string|mixed $publicKey - The public key.
     * @param int $padding - One of OPENSSL_PKCS1_PADDING, OPENSSL_SSLV23_PADDING, OPENSSL_PKCS1_OAEP_PADDING, OPENSSL_NO_PADDING, default is `OPENSSL_PKCS1_OAEP_PADDING`.
     *
     * @return string - The base64-encoded ciphertext.
     * @throws UnexpectedValueException
     */
    public static function encrypt(string $plaintext, $publicKey, int $padding = OPENSSL_PKCS1_OAEP_PADDING): string
    {
        if (!openssl_public_encrypt($plaintext, $encrypted, $publicKey, $padding)) {
            throw new UnexpectedValueException('Encrypting the input $plaintext failed, please checking your $publicKey whether or nor correct.');
        }

        return base64_encode($encrypted);
    }

    /**
     * Verifying the `message` with given `signature` string that uses `OPENSSL_ALGO_SHA256`.
     *
     * @param string $message - Content will be `openssl_verify`.
     * @param string $signature - The base64-encoded ciphertext.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|object|resource|string|mixed $publicKey - The public key.
     *
     * @return boolean - True is passed, false is failed.
     * @throws UnexpectedValueException
     */
    public static function verify(string $message, string $signature, $publicKey): bool
    {
        if (($result = openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256)) === false) {
            throw new UnexpectedValueException('Verified the input $message failed, please checking your $publicKey whether or nor correct.');
        }

        return $result === 1;
    }

    /**
     * Creates and returns a `base64_encode` string that uses `OPENSSL_ALGO_SHA256`.
     *
     * @param string $message - Content will be `openssl_sign`.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|object|resource|string|mixed $privateKey - The private key.
     *
     * @return string - The base64-encoded signature.
     * @throws UnexpectedValueException
     */
    public static function sign(string $message, $privateKey): string
    {
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new UnexpectedValueException('Signing the input $message failed, please checking your $privateKey whether or nor correct.');
        }

        return base64_encode($signature);
    }

    /**
     * Decrypts base64 encoded string with `$privateKey` in the `$padding`(default is `OPENSSL_PKCS1_OAEP_PADDING`) mode.
     *
     * Some of APIv2 were required the `$padding` mode as of `RSAES-PKCS1-v1_5` which is equal to the `OPENSSL_PKCS1_PADDING` constant, exposed it for this case.
     *
     * **Warning:** While the `$padding` is `OPENSSL_NO_PADDING` value, the `$decrypted` value must be unpadded by `caller-self`, be careful about this.
     *
     * @param string $ciphertext - Was previously encrypted string using the corresponding public key.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|array{string,string}|mixed $privateKey - The private key.
     * @param int $padding - One of OPENSSL_PKCS1_PADDING, OPENSSL_SSLV23_PADDING, OPENSSL_PKCS1_OAEP_PADDING, OPENSSL_NO_PADDING, default is `OPENSSL_PKCS1_OAEP_PADDING`.
     *
     * @return string - The utf-8 plaintext.
     * @throws UnexpectedValueException
     */
    public static function decrypt(string $ciphertext, $privateKey, int $padding = OPENSSL_PKCS1_OAEP_PADDING): string
    {
        if (!openssl_private_decrypt(base64_decode($ciphertext), $decrypted, $privateKey, $padding)) {
            throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $privateKey whether or nor correct.');
        }

        return $decrypted;
    }
}
