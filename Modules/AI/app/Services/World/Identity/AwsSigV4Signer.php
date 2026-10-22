<?php

namespace Modules\AI\Services\World\Identity;

/** Minimal AWS Signature Version 4 (header based) for the single JSON POST calls DORR makes to Rekognition. */
class AwsSigV4Signer
{
    /**
     * @param  array<string, string>  $headers  lower-case names; "host" is required
     * @return array<string, string> the headers to send: the given ones plus x-amz-date and authorization
     */
    public function sign(string $method, string $path, string $query, array $headers, string $body, string $region, string $service, string $accessKey, string $secretKey, string $amzDate): array
    {
        $date = substr($amzDate, 0, 8);
        $headers = array_change_key_case($headers, CASE_LOWER) + ['x-amz-date' => $amzDate];
        ksort($headers);

        $canonicalHeaders = '';

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim(preg_replace('/\s+/', ' ', (string) $value))."\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [strtoupper($method), $path === '' ? '/' : $path, $query, $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);
        $scope = "$date/$region/$service/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secretKey, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

        return $headers;
    }
}
