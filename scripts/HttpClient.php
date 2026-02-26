<?php

interface HttpClientInterface
{
	public function getJson(string $url): ?object;
}

class CurlHttpClient implements HttpClientInterface
{
	private string $userAgent;

	public function __construct(string $userAgent = "Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0")
	{
		$this->userAgent = $userAgent;
	}

	public function getJson(string $url): ?object
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
		$response = curl_exec($ch);
		if ($response === false) {
			error_log("curl error for {$url}: " . curl_error($ch));
			curl_close($ch);
			return null;
		}
		curl_close($ch);
		return json_decode($response);
	}
}
