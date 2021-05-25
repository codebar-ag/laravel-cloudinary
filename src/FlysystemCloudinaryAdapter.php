<?php

namespace CodebarAg\FlysystemCloudinary;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Exception\ApiError;
use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;
use CodebarAg\FlysystemCloudinary\Events\FlysystemCloudinaryResponseLog;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use LogicException;

class FlysystemCloudinaryAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    public function __construct(
        public Cloudinary $cloudinary,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function write($path, $contents, Config $config): array | false
    {
        ray('adapter write');

        return $this->upload($path, $contents);
    }

    /**
     * @inheritDoc
     */
    public function writeStream($path, $resource, Config $config): array | false
    {
        ray('adapter writeStream');

        throw new LogicException(self::class . ' does not support stream. Path: ' . $path);
    }

    /**
     * @inheritDoc
     */
    public function update($path, $contents, Config $config): array | false
    {
        ray('adapter upload');

        return $this->upload($path, $contents);
    }

    /**
     * @inheritDoc
     */
    public function updateStream($path, $resource, Config $config): array | false
    {
        ray('adapter updateStream');

        throw new LogicException(self::class . ' does not support stream. Path: ' . $path);
    }

    /**
     * https://cloudinary.com/documentation/image_upload_api_reference#upload_method
     */
    protected function upload(string $path, string $contents): array | false
    {
        $tmpFile = tmpfile();

        if (fwrite($tmpFile, $contents) === false) {
            return false;
        }

        $path = trim($path, '/');

        $options = [
            'type' => 'upload',
            'public_id' => $path,
            'use_filename' => true,
            'resource_type' => 'auto',
            'unique_filename' => false,
        ];

        try {
            $response = $this
                ->cloudinary
                ->uploadApi()
                ->upload($tmpFile, $options);
        } catch (ApiError) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        [
            'bytes' => $bytes,
            'created_at' => $created_at,
        ] = $response->getArrayCopy();

        // https://flysystem.thephpleague.com/v1/docs/architecture/
        return [
            'type' => 'file',
            'path' => $path,
            'contents' => $contents,
            'visibility' => 'public',
            'timestamp' => strtotime($created_at),
            'size' => $bytes,
            'bytes' => $bytes,
        ];
    }

    /**
     * @inheritDoc
     *
     * https://cloudinary.com/documentation/image_upload_api_reference#rename_method
     */
    public function rename($path, $newpath): bool
    {
        ray('adapter rename');

        try {
            $response = $this
                ->cloudinary
                ->uploadApi()
                ->rename($path, $newpath);
        } catch (NotFound) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        return true;
    }

    /**
     * @inheritDoc
     */
    public function copy($path, $newpath): bool
    {
        ray('adapter copy');

        $object = $this->read($path);

        if ($object === false) {
            return false;
        }

        $write = $this->write(
            $newpath,
            $object['contents'],
            resolve(Config::class),
        );

        if ($write === false) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     *
     * https://cloudinary.com/documentation/image_upload_api_reference#destroy_method
     */
    public function delete($path): bool
    {
        ray('adapter delete');

        try {
            $response = $this
                ->cloudinary
                ->uploadApi()
                ->destroy($path);
        } catch (NotFound) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteDir($dirname): bool
    {
        ray('adapter deleteDir');

        try {
            $response = $this
                ->cloudinary
                ->adminApi()
                ->deleteFolder($dirname);
        } catch (ApiError) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        return true;
    }

    /**
     * @inheritDoc
     */
    public function createDir($dirname, Config $config): array | false
    {
        ray('adapter createDir');

        try {
            $response = $this
                ->cloudinary
                ->adminApi()
                ->createFolder($dirname);
        } catch (ApiError) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        return [
            'path' => trim($dirname, '/'),
            'type' => 'dir',
        ];
    }

    /**
     * @inheritDoc
     *
     * https://cloudinary.com/documentation/image_upload_api_reference#explicit_method
     */
    public function has($path): array | bool | null
    {
        ray('adapter has');

        $options = [
            'type' => 'upload',
        ];

        try {
            $response = $this
                ->cloudinary
                ->uploadApi()
                ->explicit($path, $options);
        } catch (NotFound) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        return true;
    }

    /**
     * @inheritDoc
     */
    public function read($path): array | false
    {
        ray('adapter read');

        $object = $this->readStream($path);

        if ($object === false) {
            return false;
        }

        return [
            'contents' => $object['stream'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function readStream($path): array | false
    {
        ray('adapter readStream');

        $url = $this->getUrl($path);

        $contents = file_get_contents($url);

        if ($contents === false) {
            return false;
        }

        return [
            'stream' => $contents,
        ];
    }

    /**
     * @inheritDoc
     */
    public function listContents($directory = '', $recursive = false): array
    {
        ray('adapter listContents');

        $options = [
            'type' => 'upload',
            'prefix' => $directory,
        ];

        try {
            $response = $this
                ->cloudinary
                ->adminApi()
                ->assets($options);
        } catch (ApiError) {
            return [];
        }

        ['resources' => $resources] = $response->getArrayCopy();

        return array_map(function ($resource) {
            return [
                'path' => $resource['public_id'],
                'size' => $resource['bytes'],
                'type' => 'file',
                'version' => $resource['version'],
                'timestamp' => strtotime($resource['created_at']),
            ];
        }, $resources);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($path): array | false
    {
        ray('adapter getMetadata');

        $options = [
            'type' => 'upload',
        ];

        try {
            /** @var ApiResponse $response */
            $response = $this
                ->cloudinary
                ->uploadApi()
                ->explicit($path, $options);
        } catch (NotFound) {
            return false;
        }

        event(new FlysystemCloudinaryResponseLog($response));

        ['storage' => $storage] = $response->getArrayCopy();

        return [
            'path' => $storage['public_id'],
            'size' => $storage['bytes'],
            'type' => 'file',
            'version' => $storage['version'],
            'timestamp' => strtotime($storage['created_at']),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSize($path): array | false
    {
        ray('adapter getSize');

        return $this->getMetadata($path);
    }

    /**
     * @inheritDoc
     */
    public function getMimetype($path): array | false
    {
        ray('adapter getMimetype');

        $contents = $this->read($path);

        $temp = tmpfile();

        fwrite($temp, $contents['contents']);

        $mime = mime_content_type($temp);

        fclose($temp);

        if ($mime === false) {
            return false;
        }

        return [
            'mimetype' => $mime,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getTimestamp($path): array | false
    {
        ray('adapter getTimestamp');

        return $this->getMimetype($path);
    }

    public function getUrl(string $path): string
    {
        ray('adapter getUrl');

        $options = [
            'type' => 'upload',
        ];

        /** @var ApiResponse $response */
        $response = $this
            ->cloudinary
            ->uploadApi()
            ->explicit($path, $options);

        event(new FlysystemCloudinaryResponseLog($response));

        ['url' => $url] = $response->getArrayCopy();

        return $url;
    }
}
