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
    protected $appkey;

    protected $serverHost = 'data.icecat.biz';
    protected $serverScheme = 'http';

    protected $paths = [
        'all' => '/export/level4/EN/files.index.xml',
        'update' => '/export/level4/EN',
        'categories' => '/export/freexml.int/refs/CategoriesList.xml.gz',
        'suppliers' => '/export/freexml.int/refs/SuppliersList.xml.gz',
    ];

    public function __construct(array $config = [])
    {
        if (!empty($config['username'])) $this->username = $config['username'];
        if (!empty($config['password'])) $this->password = $config['password'];
        if (!empty($config['appkey'])) $this->appkey = $config['appkey'];
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
            'protocol' => null,
            'scheme' => $this->serverScheme,
            'host' => $this->serverHost,
            'auth' => $this->auth(),
            'path' => '/',
            'params' => [],
        ], $config);

        if ($config['path'] && $config['path'] !== '/')
            $config['path'] = '/' . $config['path'];

        $prefix = join('', array_filter([
            $config['protocol'] ? $config['protocol'] . '://' : null,
            $config['scheme'],
            '://',
            $config['auth'] ? $config['auth'] . '@' : null,
            $config['host'],
            $config['path'],
            !empty($config['params']) ? '?' . http_build_query($config['params']): null,
        ]));

        return $prefix;
    }

    public function fetchProducts($callback, $limit = 20, $start = 0, $gatherMeta = true)
    {
        $url = $this->url([
            'path' => $this->paths['all'],
        ]);

        $this->readProductXml($url, $callback, $limit, $start, $gatherMeta);
    }

    public function fetchUpdatedProducts($callback, $limit = 20, $start = 0)
    {
        $url = $this->url([
            'path' => $this->paths['update'],
        ]);

        $this->readProductXml($url, $callback, $limit, $start);
    }

    public function fetchCategories($callback, $limit = 20, $start = 0)
    {
        $url = $this->url([
            'protocol' => 'compress.zlib',
            'path' => $this->paths['categories'],
        ]);

        $this->readCategoryXml($url, $callback, $limit, $start);
    }

    public function fetchSuppliers($callback, $limit = 20, $start = 0)
    {
        $url = $this->url([
            'protocol' => 'compress.zlib',
            'path' => $this->paths['suppliers'],
        ]);

        $this->readSupplierXml($url, $callback, $limit, $start);
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
                    'appkey' => $this->appkey,
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
            'appkey' => $this->appkey,
        ]);

        try
        {
            $xml = new \XMLReader();
            $xml->open($url);
        }
        catch(\Exception $e)
        {
            // throw $e;
            return null;
        }

        while ($xml->read() && $xml->name !== 'Product');

        $p = xml_parser_create();
        xml_parse_into_struct($p, $xml->readOuterXML(), $vals, $index);
        xml_parser_free($p);

        return $vals[0]['attributes'];
    }

    private function readProductXml($url, $callback, $limit, $start, $gatherMeta = true)
    {
        $xml = new \XMLReader();
        $xml->open($url);

        $count = 0;
        $skipped = 0;

        while ($xml->read() && $xml->name !== 'file');

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

            if ($gatherMeta)
            {
                $product = $this->fetchProduct($attributes['path']);
                $product['CATEGORY_ID'] = $attributes['Catid'];
                $product['SUPPLIER_ID'] = $attributes['Supplier_id'];
            }
            else
            {
                $product = [
                    'ID' => $attributes['Product_ID'],
                    'PROD_ID' => $attributes['Prod_ID'],
                    'NAME' => $attributes['Model_Name'],
                    'CATEGORY_ID' => $attributes['Catid'],
                    'SUPPLIER_ID' => $attributes['Supplier_id'],
                ];
            }

            if (!$product)
            {
                ++$count;

                unset($file, $attributes);
                $xml->next('file');
                continue;
            }

            // die('<pre>'.print_r(['prod', $product, $attributes], 1).'</pre>');

            $product['CATEGORY_ID'] = $attributes['Catid'];
            $product['SUPPLIER_ID'] = $attributes['Supplier_id'];

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

    private function readCategoryXml($url, $callback, $limit, $start)
    {
        $xml = simplexml_load_file($url);

        $count = 0;
        $skipped = 0;

        foreach ($xml->Response->CategoriesList->Category as $object)
        {
            if ($count >= $limit)
            {
                break;
            }

            if ($skipped < $start)
            {
                ++$skipped;
                continue;
            }

            // $attributes = (array) array_values((array) $object->attributes())[0];

            if (!$object->Name[0] || empty($object->Name[0]->attributes()->Value))
                continue;

            $response = $callback([
                'ID' => (int) $object->attributes()->ID,
                'NAME' => (string) $object->Name[0]->attributes()->Value,
            ]);

            if (!$response)
            {
                break;
            }

            ++$count;

            unset($object, $attributes);
        }
    }

    private function readSupplierXml($url, $callback, $limit, $start)
    {
        $xml = simplexml_load_file($url);

        $count = 0;
        $skipped = 0;

        foreach ($xml->Response->SuppliersList->Supplier as $object)
        {
            if ($count >= $limit)
            {
                break;
            }

            if ($skipped < $start)
            {
                ++$skipped;
                continue;
            }

            $response = $callback([
                'ID' => (int) $object->attributes()->ID,
                'NAME' => (string) $object->attributes()->Name,
                'LOGOPIC' => (string) $object->attributes()->LogoPic,
                'LOGOLOWPIC' => (string) $object->attributes()->LogoLowPic,
                'LOGOHIGHPIC' => (string) $object->attributes()->LogoHighPic,
                'LOGOORIGINAL' => (string) $object->attributes()->LogoOriginal,
            ]);

            if (!$response)
            {
                break;
            }

            ++$count;

            unset($object, $attributes);
        }
    }

    public function fetchUrl($url)
    {
        return $this->client()->request(
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
}
