<?php

namespace MeloLab\BioGestion\FileUploadBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class MeloLabBioGestionFileUploadExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        
        // Set configuration default values
        $config = $this->setConfigurationDefaultValues($config);

        // Store params in container
        $container->setParameter('melolab_biogestion_fileupload.max_file_size', $config['max_file_size']);
        $container->setParameter('melolab_biogestion_fileupload.accepted_file_types', $config['accepted_file_types']);
        $container->setParameter('melolab_biogestion_fileupload.temp_files_path', $config['temp_files_path']);
        $container->setParameter('melolab_biogestion_fileupload.mappings', $config['mappings']);

        // Validate download_allowed_referer_routes is given when download_ignore_security is enabled
        $this->validateDownloadAllowedRefererRoutes($config['mappings']);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
//        $loader->load('config.yml');
    }

    /**
     * Validate download_allowed_referer_routes is given when download_ignore_security is enabled
     * @param $mappings
     */
    private function validateDownloadAllowedRefererRoutes($mappings) {
        foreach ($mappings as $mapping => $val) {
            if ($val['download_ignore_security']) {
                if (!$val['download_allowed_referer_routes']) {
                    throw new InvalidConfigurationException('Error for mapping "'. $mapping .'": Option "download_allowed_referer_routes" is required when "download_ignore_security" is enabled.');
                }
            }
        }
    }
    
    /**
     * Sets some default values of the bundle configuration
     * @param array $config Bundle configuration array
     * @return array Bundle configuration with added default values
     */
    private function setConfigurationDefaultValues(array $config) {
        foreach ($config as $key0 => $val0) {
            if ('mappings' == $key0) {
                foreach ($val0 as $key1 => $val1) {
                    // Set default file_field value
                    if (!isset($val1['file_field'])) {
                        $parts = explode('_', $key1);
                        for ($i = 0; $i < count($parts); $i++) {
                            if ($i > 0) {
                                $parts[$i] = ucfirst($parts[$i]); // Capitalize first letter, except for the first part
                            }
                        }
                        $config[$key0][$key1]['file_field'] = implode('', $parts);
                    }
                    // Set default file_getter value
                    if (!isset($val1['file_getter'])) {
                        $config[$key0][$key1]['file_getter'] = 'get'.ucfirst($config[$key0][$key1]['file_field']);
                    }
                    // Set default file_setter value
                    if (!isset($val1['file_setter'])) {
                        $config[$key0][$key1]['file_setter'] = 'set'.ucfirst($config[$key0][$key1]['file_field']);
                    }
                    // Set default filename_getter value
                    if (!isset($val1['filename_getter'])) {
                        $config[$key0][$key1]['filename_getter'] = 'get'.ucfirst($config[$key0][$key1]['file_field']).'Name';
                    }
                    // Set default filename_getter value
                    if (!isset($val1['filename_getter'])) {
                        $config[$key0][$key1]['filename_setter'] = 'set'.ucfirst($config[$key0][$key1]['file_field']).'Name';
                    }
                    // Set default vich_mapping
                    if (!isset($val1['vich_mapping'])) {
                        $config[$key0][$key1]['vich_mapping'] = $key1;
                    }
                }
            }
        }
        
        return $config;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getAlias() {
        return 'melolab_biogestion_fileupload';
    }
}
