<?php

namespace ethercreative\icecat;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

class Reader
{
    protected $language = 'en';

    protected $username;
    protected $password;

    protected $serverHost = 'data.icecat.biz';
    protected $serverScheme = 'http';

    protected $paths = [
        'all' => '/export/level4/EN/files.index.xml',
        'update' => '/export/level4/EN',
    ];

    public function __construct(array $config = [])
    {
        if (!empty($config['username'])) $this->username = $config['username'];
        if (!empty($config['password'])) $this->password = $config['password'];
    }

    private function client(MockHandler $handler = null)
    {
        return new Client([
            'base_uri' => $this->serverHost,
            'handler' => $handler,
        ]);
    }

    private function auth()
    {
        return join(':', [$this->username, $this->password]);
    }

    private function url(array $config = [])
    {
        $config = array_replace_recursive([
            'scheme' => $this->serverScheme,
            'host' => $this->serverHost,
            'auth' => $this->auth(),
            'path' => '/',
            'params' => [],
        ], $config);

        if ($config['path'] && $config['path'] !== '/')
            $config['path'] = '/' . $config['path'];

        $prefix = join('', array_filter([
            $config['scheme'],
            '://',
            $config['auth'] ? $config['auth'] . '@' : null,
            $config['host'],
            $config['path'],
            !empty($config['params']) ? '?' . http_build_query($config['params']): null,
        ]));

        return $prefix;
    }

    public function fetchProducts($callback, $limit = 20, $start = 0)
    {
        $url = $this->url([
            'path' => $this->paths['all'],
        ]);

        $this->readXML($url, $callback, $limit, $start);
    }

    public function fetchUpdatedProducts($callback, $limit = 20, $start = 0)
    {
        $url = $this->url([
            'path' => $this->paths['update'],
        ]);

        $this->readXML($url, $callback, $limit, $start);
    }

    public function fetchProduct($id)
    {
        // use json api if id is specified
        if (is_numeric($id))
        {
            $url = $this->url([
                'scheme' => 'https',
                'host' => 'live.icecat.biz',
                'path' => '/api/',
                'auth' => false,
                'params' => [
                    'shopname' => $this->username,
                    'lang' => $this->language,
                    'content' => '',
                    'icecat_id' => $id,
                ],
            ]);

            try {
                $client = $this->client()->request(
                    'GET',
                    $url,
                    [
                        'verify' => true,
                        'auth' => [
                            $this->username,
                            $this->password,
                        ],
                    ]
                );
            }
            catch (\Exception $e)
            {
                // die('<pre>'.print_r($e, 1).'</pre>');
                die('<pre>'.print_r($e->getMessage(), 1).'</pre>');
            }

            echo $client->getBody()->getContents();
            exit;
        }

        $url = $this->url([
            'scheme' => 'https',
            'host' => 'data.icecat.biz',
            'path' => $id,
        ]);

        $xml = new \XMLReader();
        $xml->open($url);

        while ($xml->read() && $xml->name !== 'Product');

        $p = xml_parser_create();
        xml_parse_into_struct($p, $xml->readOuterXML(), $vals, $index);
        xml_parser_free($p);

        return $vals[0]['attributes'];
    }

    private function readXML($url, $callback, $limit, $start)
    {
        $xml = new \XMLReader();
        $xml->open($url);

        while ($xml->read() && $xml->name !== 'file');

        $count = 0;
        $skipped = 0;

        while ($xml->name === 'file')
        {
            if ($count >= $limit)
            {
                break;
            }

            if ($skipped < $start)
            {
                ++$skipped;
                $xml->next('file');
                continue;
            }

            $file = new \SimpleXMLElement($xml->readOuterXML());

            $attributes = (array) array_values((array) $file->attributes())[0];

            $product = $this->fetchProduct($attributes['path']);

            $response = $callback($product);

            if (!$response)
            {
                break;
            }

            ++$count;

            unset($file, $attributes);
            $xml->next('file');
        }
    }
}
