<?php declare(strict_types=1);

namespace Igni\Network\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Swoole\Http\Request as SwooleHttRequest;
use function Zend\Diactoros\marshalHeadersFromSapi;
use Zend\Diactoros\ServerRequestFactory;

use function Zend\Diactoros\marshalMethodFromSapi;
use function Zend\Diactoros\marshalProtocolVersionFromSapi;
use function Zend\Diactoros\marshalUriFromSapi;
use function Zend\Diactoros\normalizeUploadedFiles;

class ServerRequest extends Request implements ServerRequestInterface
{
    /**
     * @var array
     */
    private $attributes = [];

    /**
     * @var array
     */
    private $cookieParams = [];

    /**
     * @var null|array|object
     */
    private $parsedBody;

    /**
     * @var array
     */
    private $queryParams = [];

    /**
     * @var array
     */
    private $serverParams;

    /**
     * @var array
     */
    private $uploadedFiles;


    /**
     * Server request constructor.
     *
     * @param array $serverParams Server parameters, typically from $_SERVER
     * @param array $uploadedFiles Upload file information, a tree of UploadedFiles
     * @param null|string $uri URI for the request, if any.
     * @param null|string $method HTTP method for the request, if any.
     * @param string|resource|StreamInterface $body Messages body, if any.
     * @param array $headers Headers for the message, if any.
     * @throws \InvalidArgumentException for any invalid value.
     */
    public function __construct(
        string $method = self::METHOD_GET,
        string $uri = null,
        array $serverParams = [],
        $body = 'php://input',
        array $uploadedFiles = [],
        array $headers = []
    ) {
        parent::__construct($uri, $method, $body, $headers);
        $this->validateUploadedFiles($uploadedFiles);
        $this->serverParams  = $serverParams;
        $this->uploadedFiles = $uploadedFiles;
        parse_str($this->getUri()->getQuery(), $this->queryParams);
    }

    /**
     * {@inheritdoc}
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * {@inheritdoc}
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * {@inheritdoc}
     */
    public function withUploadedFiles(array $uploadedFiles)
    {
        $this->validateUploadedFiles($uploadedFiles);
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * {@inheritdoc}
     */
    public function withCookieParams(array $cookies)
    {
        $new = clone $this;
        $new->cookieParams = $cookies;
        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * {@inheritdoc}
     */
    public function withQueryParams(array $query)
    {
        $new = clone $this;
        $new->queryParams = $query;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    /**
     * {@inheritdoc}
     */
    public function withParsedBody($data)
    {
        $new = clone $this;
        $new->parsedBody = $data;
        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function getAttribute($attribute, $default = null)
    {
        if (! isset($this->attributes[$attribute])) {
            return $default;
        }

        return $this->attributes[$attribute];
    }

    /**
     * {@inheritdoc}
     */
    public function withAttribute($attribute, $value)
    {
        $new = clone $this;
        $new->attributes[$attribute] = $value;
        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutAttribute($attribute)
    {
        if (!isset($this->attributes[$attribute])) {
            return clone $this;
        }

        $new = clone $this;
        unset($new->attributes[$attribute]);
        return $new;
    }

    /**
     * Sets request attributes
     *
     * This method returns a new instance.
     *
     * @param array $attributes
     * @return self
     */
    public function withAttributes(array $attributes): ServerRequest
    {
        $new = clone $this;
        $new->attributes = $attributes;
        return $new;
    }

    /**
     * Recursively validate the structure in an uploaded files array.
     *
     * @param array $uploadedFiles
     * @throws \InvalidArgumentException if any leaf is not an UploadedFileInterface instance.
     */
    private function validateUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            if (is_array($file)) {
                $this->validateUploadedFiles($file);
                continue;
            }

            if (! $file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('Invalid leaf in uploaded files structure');
            }
        }
    }

    public static function fromGlobals(): ServerRequest
    {
        $instance = new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER,
            'php://input',
            normalizeUploadedFiles($_FILES),
            marshalHeadersFromSapi($_SERVER)
        );

        return $instance;
    }

    /**
     * @param SwooleHttpRequest $request
     * @return ServerRequest
     */
    public static function fromSwooleRequest(SwooleHttpRequest $request): ServerRequest
    {
        if (isset($request->server['query_string'])) {
            $uri = $request->server['request_uri'] . '?' . $request->server['query_string'];
        } else {
            $uri = $request->server['request_uri'];
        }

        // Parse headers
        $headers = [];
        if ($request->header) {
            foreach ($request->header as $name => $value) {
                if (!isset($headers[$name])) {
                    $headers[$name] = [];
                }
                array_push($headers[$name], $value);
            }
        }

        try {
            $body = $request->rawContent();
        } catch (\Throwable $throwable) {
            $body = '';
        }

        if (!$body) {
            $body = '';
        }

        return new ServerRequest(
            $request->server ?? [],
            ServerRequestFactory::normalizeFiles($request->files ?? []) ?? [],
            $uri,
            $request->server['request_method'] ?? 'GET',
            $body,
            $headers
        );
    }

    public static function fromUri($uri, $method = self::METHOD_GET, string $body = ''): ServerRequest
    {
        return new ServerRequest([], [], $uri, $method, $body);
    }
}
