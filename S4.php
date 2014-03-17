<?php

class S4
{
  const ENDPOINT_TEMPLATE     = 'https://@host.amazonaws.com';

  const REGION_AUSTRALIA      = 'ap-southeast-2';
  const REGION_BRAZIL         = 'sa-east-1';
  const REGION_CALIFORNIA     = 'us-west-1';
  const REGION_IRELAND        = 'eu-west-1';
  const REGION_JAPAN          = 'ap-northeast-1';
  const REGION_OREGON         = 'us-west-2';
  const REGION_SINGAPORE      = 'ap-southeast-1';
  const REGION_VIRGINIA       = 'us-east-1';

  const ACL_PRIVATE           = 'private';
  const ACL_PUBLIC_READ       = 'public-read';
  const ACL_PUBLIC_FULL       = 'public-read-write';
  const ACL_AUTH_READ         = 'authenticated-read';
  const ACL_OWNER_READ        = 'bucket-owner-read';
  const ACL_OWNER_FULL        = 'bucket-owner-full-control';
  const ACL_LOG_WRITE         = 'log-delivery-write';

  const ENCRYPTION_AES256     = 'AES256';

  const REDUNDANCY_STANDARD   = 'STANDARD';
  const REDUNDANCY_REDUCED    = 'REDUCED_REDUNDANCY';

  const HEADER_ACL            = 'x-amz-acl';
  const HEADER_ENCRYPTION     = 'x-amz-server-side-encryption';
  const HEADER_REDUNDANCY     = 'x-amz-storage-class';
  const HEADER_SHA256         = 'x-amz-content-sha256';


  protected $accessKey;

  protected $secretKey;

  protected $bucket;

  protected $region;

  protected $endpoint;


  public function __construct($accessKey, $secretKey, $bucket, $region = self::REGION_VIRGINIA)
  {
    $this->accessKey = $accessKey;
    $this->secretKey = $secretKey;
    $this->bucket = $bucket;
    $this->region = $region;

    $host = ($region === self::REGION_VIRGINIA) ? 's3' : "s3-$region";
    $this->endpoint = str_replace('@host', $host, static::ENDPOINT_TEMPLATE);
  }


  public function upload($key, $file, $acl = self::ACL_PRIVATE, $redundancy = self::REDUNDANCY_STANDARD, $headers = array())
  {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if (is_file($file)) {
      $handle = fopen($file, 'r');
      $hash = hash_file('sha256', $file);
      $length = filesize($file);
      $type = finfo_file($finfo, $file);
    }
    elseif (is_resource($file)) {
      $handle = $file;
      $file = stream_get_meta_data($handle)['uri'];
      $hash = hash_file('sha256', $file);
      $length = filesize($file);
      $type = finfo_file($finfo, $file);
    }
    else {
      $handle = fopen('php://temp', 'w+');
      $hash = hash('sha256', $file);
      $length = strlen($file);
      $type = 'text/plain';
      fwrite($handle, $file, $length);
    }

    rewind($handle);
    $cache = (0 === strpos($acl, 'public')) ? 'public' : 'private';
    $path = "/$this->bucket/". ltrim($key, '/');
    $headers = array_replace(
      array(
        static::HEADER_ACL        => $acl,
        static::HEADER_REDUNDANCY => $redundancy,
        static::HEADER_SHA256     => $hash,
        'Cache-Control'           => $cache,
        'Content-Length'          => $length,
        'Content-Type'            => $type
      ),
      $headers
    );
    $options = array(
      CURLOPT_PUT             => true,
      CURLOPT_INFILE          => $handle,
      CURLOPT_INFILESIZE      => $length
    );

    $result = $this->request('PUT', $path, $headers, $options);

    fclose($handle);
    finfo_close($finfo);

    return $result;
  }


  public function download($key, $file = null)
  {
  }


  public function remove($key)
  {
  }


  public function request($method = 'GET', $path = '/', $headers = array(), $options = array())
  {
    $path = '/'. ltrim($path, '/');
    $url = $this->endpoint . $path;

    $headers = array_replace(
      array(
        'Date'                => gmdate('D, d M Y H:i:s \G\M\T'),
        'Host'                => parse_url($url, PHP_URL_HOST),
        static::HEADER_SHA256 => hash('sha256', '')
      ),
      $headers
    );
    ksort($headers);
    $headers['Authorization'] = $this->sign($method, $url, $headers);

    $formatted = array();
    foreach ($headers as $key => $value) {
      $formatted[] = "$key: $value";
    }

    $options = array_replace(
      array(
        CURLOPT_URL           => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER    => $formatted
      ),
      $options
    );

    return $this->curl($options);
  }


  protected function sign($method, $url, $headers)
  {
    $date       = gmdate('Ymd', strtotime($headers['Date']));
    $query      = parse_url($url, PHP_URL_QUERY);
    $path       = parse_url($url, PHP_URL_PATH);

    $canonical  = array();
    foreach ($headers as $key => $value) {
      $canonical[] = sprintf('%s:%s', strtolower($key), trim($value));
    }
    $canonical  = implode("\n", $canonical);
    $signed     = implode(';', array_map('strtolower', array_keys($headers)));

    $request    = sprintf(
      "%s\n%s\n%s\n%s\n\n%s\n%s",
      $method, $path, $query, $canonical, $signed, $headers[static::HEADER_SHA256]
    );
    $checksum   = hash('sha256', $request);

    $scope      = sprintf('%s/%s/s3/aws4_request', $date, $this->region);
    $string     = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $headers['Date'], $scope, $checksum);
    $key        = $this->keygen($date);

    $signature  = hash_hmac('sha256', $string, $key);

    return sprintf(
      'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
      $this->accessKey, $scope, $signed, $signature
    );
  }


  protected function keygen($date)
  {
    $region     = $this->region;
    $service    = 's3';
    $format     = 'aws4_request';

    $secretKey  = "AWS4$this->secretKey";
    $dateKey    = hash_hmac('sha256', $date,    $secretKey,   true);
    $regionKey  = hash_hmac('sha256', $region,  $dateKey,     true);
    $serviceKey = hash_hmac('sha256', $service, $regionKey,   true);
    $signingKey = hash_hmac('sha256', $format,  $serviceKey,  true);

    return $signingKey;
  }


  /**
   * @param array $options
   * @return array
   */
  protected function curl($options)
  {
    // obtain curl handle
    $handle = curl_init();

    // configure curl
    curl_setopt_array($handle, array_replace(
      array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
      ),
      $options
    ));

    // perform post request, gather response data
    $result = curl_exec($handle);
    $error = curl_error($handle);
    $info = curl_getinfo($handle);

    // close curl handle
    curl_close($handle);

    // process response data
    return array_merge($info, compact('result', 'error'));
  }
}