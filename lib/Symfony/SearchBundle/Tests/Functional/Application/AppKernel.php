<?php

declare(strict_types=1);

/*
 * This file is part of the RollerworksSearch package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Bundle\SearchBundle\Tests\Functional\Application;

use ApiPlatform\Core\Bridge\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\DoctrineCacheBundle\DoctrineCacheBundle;
use FOS\ElasticaBundle\FOSElasticaBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

class AppKernel extends Kernel
{
    private $config;

    public function __construct($config, $debug = true)
    {
        if (!(new Filesystem())->isAbsolutePath($config)) {
            $config = __DIR__.'/config/'.$config;
        }

        if (!file_exists($config)) {
            throw new \RuntimeException(sprintf('The config file "%s" does not exist.', $config));
        }

        $this->config = $config;

        parent::__construct('test', $debug);
    }

    public function serialize()
    {
        return serialize([$this->config, $this->debug]);
    }

    public function unserialize($data)
    {
        list($environment, $debug) = unserialize($data, ['allowed_classes' => false]);

        $this->__construct($environment, $debug);
    }

    public function getName()
    {
        return 'RSearch'.substr(sha1($this->config), 0, 6);
    }

    public function registerBundles()
    {
        $bundles = [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            //new \Symfony\Bundle\TwigBundle\TwigBundle(),

            new \Rollerworks\Bundle\SearchBundle\RollerworksSearchBundle(),
            new AppBundle\AppBundle(),
        ];

        if (class_exists(DoctrineBundle::class)) {
            $bundles[] = new DoctrineCacheBundle();
            $bundles[] = new DoctrineBundle();
        }

        if (class_exists(FOSElasticaBundle::class)) {
            $bundles[] = new FOSElasticaBundle();
        }

        if ('api_platform.yml' === substr($this->config, -16)) {
            $bundles[] = new TwigBundle();
            $bundles[] = new ApiPlatformBundle();
        }

        return $bundles;
    }

    public function getRootDir()
    {
        if (null === $this->rootDir) {
            $this->rootDir = str_replace('\\', '/', __DIR__);
        }

        return $this->rootDir;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load($this->config);
    }

    public function getCacheDir()
    {
        if (false === $tmpDir = getenv('TMPDIR')) {
            $tmpDir = sys_get_temp_dir();
        }

        return rtrim($tmpDir, '/\\').'/rollerworks-search-'.sha1(__DIR__).'/'.substr(sha1($this->config), 0, 6);
    }

    protected function getKernelParameters()
    {
        $parameters = parent::getKernelParameters();
        $parameters['kernel.container_class'] = 'K'.substr(sha1($this->config), 0, 8);

        return $parameters;
    }
}
