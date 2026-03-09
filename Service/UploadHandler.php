<?php

namespace MeloLab\BioGestion\FileUploadBundle\Service;

use Doctrine\ORM\EntityManager;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Translation\TranslatorInterface;
use Vich\UploaderBundle\Exception\MappingNotFoundException;
use Vich\UploaderBundle\Handler\AbstractHandler;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * This service provides handles file ajax uploads
 *
 * @author Andreas Schueller <aschueller@bio.puc.cl>
 */
class UploadHandler {
    
    /* @var $em EntityManager */
    private $em;
    
    /* @var $container ContainerInterface */
    private $container;
    
    /* @var $securityContext SecurityContext */
    private $securityContext;

    /* @var $translator TranslatorInterface */
    private $translator;
    
    /* @var FormFactoryInterface $formFactory */
    private $formFactory;
    
    /* @var RouterInterface $router */
    private $router;

    /* @var PropertyMappingFactory $propertyMappingFactory */
    private $propertyMappingFactory;


    public function __construct(EntityManager $em, ContainerInterface $container, SecurityContext $securityContext, TranslatorInterface $translator, FormFactoryInterface $formFactory, RouterInterface $router, PropertyMappingFactory $propertyMappingFactory) {
        $this->em = $em;
        $this->container = $container;
        $this->securityContext = $securityContext;
        $this->translator = $translator;
        $this->formFactory = $formFactory;
        $this->router = $router;
        $this->propertyMappingFactory = $propertyMappingFactory;
    }
    
    /**
     * Handle AJAX file uploads
     * 
     * @param Request $request The HTTP request
     * @param int $id The entity ID. If ID == 0 a new (to be created) entity is assumed
     * @return Response
     * @throws Exception
     */
    public function handleUpload(Request $request, $id, FormTypeInterface $formType = null, $formOptions = array(), $isAdd = false) {
        // Get mapping key for config access
        $mapping = $request->query->get('mapping');
        if (!$mapping) {
            throw new RuntimeException($this->translator->trans('file.mapping_not_found'));
        }
        
        $entityClass = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['entity'];
        $repository_method = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['repository_method'];
        if (!$formType) {
            $formType = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['form_type'];
        }
        $fileField = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['file_field'];
        $allowAnonymousUploads = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['allow_anonymous_uploads'];
//        var_dump($this->get('vich_uploader.metadata_reader')->getUploadableFields(\Symfony\Component\Security\Core\Util\ClassUtils::getRealClass($lr)));
//        var_dump($this->container->getParameter('melolab_biogestion_fileupload.mappings'));
//        var_dump($this->container->getParameter('vich_uploader.mappings')); die();

        // Fetch existing entity
        if ($id) {
            $this->em->clear(); // Clear EntityManager to fetch a fresh copy

            $entity = $this->em->getRepository($entityClass)->$repository_method($id);

            if (!$entity) {
                throw new NotFoundHttpException($this->translator->trans('file.entity_not_found'));
            }

            // Security
            if (false === $allowAnonymousUploads && false === $this->securityContext->isGranted('EDIT', $entity)) {
                throw new AccessDeniedException();
            }
        }
        // Create new entity
        else {
            $entity = new $entityClass();

            // Security
            if (false === $allowAnonymousUploads && false === $this->securityContext->isGranted('CREATE', $entity)) {
                throw new AccessDeniedException();
            }
        }

        $form = $this->formFactory->create(new $formType(), $entity, $formOptions);

//        // Submit form without overwriting entity values with nulls.
//        // Actually, we just want to validate the token and file upload
//        $form->submit($request, false);
        // TODO: $form->submit($request, false) using GrantType does not work since $form->get('investigators')->isValid() === false
        // and this is due to $form->get('investigators')->isSubmitted() === false. Likely a bug of $form->submit().
        // Workaround: We use a GrantFileType which only contains file fields and use $form->handleRequest($request).
        $form->handleRequest($request);

        $errorMessages = array();
        $ok = true;
        $file = $form->get($fileField)->getData();
        if ($file) {
            $response = array('files' => array(array(
                'name' => $file->getClientOriginalName() ,
                'size' => $file->getClientSize(),
                'type' => $file->getClientMimeType(),
            )));
        } else {
            $response = array('files' => array(array(
                'name' => '',
                'size' => 0,
                'type' => '',
            )));
        }



        if ($form->isValid()) {
            
            // Persist entity
            $this->em->persist($entity);

            if(!$isAdd){
                $this->em->flush();

                // TO-DO: 'id' => $id will break for newly created objects
                // However, $entity->getId() does not work when, e.g. a token is used.
                $response['files'][0]['url'] = $this->router->generate('biogestion_fileupload_download', array('id' => $id, 'mapping' => $mapping));
            }
            else{
                $filenameGetter = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['filename_getter'];
                $fileGetter = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['file_getter'];
                $response['real_filename'] = $entity->$filenameGetter();
                $response['hiddenField'] = $fileField . '_fileuploadtemp';

                $tempPath = $fileField = $this->container->getParameter('melolab_biogestion_fileupload.temp_files_path');
                $filename = $entity->$filenameGetter();

                $entity->$fileGetter()->move($tempPath,$filename);
                $response['files'][0]['url'] = $this->router->generate('biogestion_fileupload_download_temp', array('filename' => $filename, 'mapping' => $mapping));
//                var_dump($entity->getContractDigitalCopy()->getRealPath());
//                var_dump($entity->getContractDigitalCopyName());
            }

            // Generate new form action with added ID
//            if (!$id and $entity->getId()) {
//                $response['formAction'] = $this->generateUrl('eva_research_ref_create', array('id' => $entity->getId()));
//            }
            

            //'deleteUrl' => '',
            //'deleteType' => 'DELETE',
            
            // Update reference text
//            $response['refText'] = $this->renderView('MeloLabBioGestionResearchBundle:Reference:reference.html.twig', array(
//                'ref' => $entity,
//            ));
            
            // Update PDF file download link
//            if ($entity->getPublicationFile()) {
//                $response['fileText'] = $this->renderView('MeloLabBioGestionResearchBundle:Reference:referencePublicationFile.html.twig', array(
//                    'ref' => $entity,
//                ));
//            }
        } else {
            $ok = false;
            
            // Get file form errors
            foreach ($form->get($fileField)->getErrors() as $error) {
                $errorMessages[] = $error->getMessage();
            }
//            var_dump($form->getErrors());
            
            // Other errors
            if (!$errorMessages) {
                // Check whether php.ini POST_MAX_SIZE was exceeded
                // Source: http://andrewcurioso.com/2010/06/detecting-file-size-overflow-in-php/
                if ( isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0 ) {
                    $errorMessages[] = $this->translator->trans('file.upload.post_max_size_error');
                } else { // Unknown error. Form did not validate?
                    $errorMessages[] = $this->translator->trans('file.upload.unknown_error');
                }
            }

            $response['files'][0]['error'] = implode('. ', $errorMessages);
        }

        $response['ok'] = $ok;
        $response['id'] = $id;
//        var_dump($id);

        // Fix for IE9
        if (!in_array('application/json', $request->getAcceptableContentTypes())) {
            $contentType = 'text/plain';
        } else {
            $contentType = 'application/json';
        }
        
        // Expected JSON response format:
        // https://github.com/blueimp/jQuery-File-Upload/wiki/Setup#using-jquery-file-upload-ui-version-with-a-custom-server-side-upload-handler
        return new Response(json_encode($response), 200, array('Content-Type' => $contentType));
    }

    public function moveUploadedFiles($entity, $form){


        $result = true;

        foreach($form->all() as $child){
            $childName = $child->getName();
            $splittedChildName = explode('_',$childName);
            $stringEnd = end($splittedChildName);

            if($stringEnd === 'fileuploadtemp'){
                array_pop($splittedChildName);
                $fileField = implode('_',$splittedChildName);
                //$vichMapping = $this->container->get('vich_uploader.upload_handler')->getMapping($entity, $fileField)->getMappingName();
                $vichMapping = $this->getMapping($entity, $fileField)->getMappingName();

                //This loop will always find a value, if not, is a configuration error
                foreach($this->container->getParameter('melolab_biogestion_fileupload.mappings') as $key => $value){
                    if($value["vich_mapping"] === $vichMapping){
                        $mapping = $key;
                    }
                }

//                $vichMapping = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['vich_mapping'];
                $uploadFolder = $this->container->getParameter('vich_uploader.mappings')[$vichMapping]['upload_destination'];
                $tempFolder = $this->container->getParameter('melolab_biogestion_fileupload.temp_files_path');
                $setterMethod = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['filename_setter'];
                $filename = $form->get($fileField."_fileuploadtemp")->getData();

                if($filename){
                    try{
                        $file = new File($tempFolder.'/'.$filename);
                        $file->move($uploadFolder,$filename);
                    } catch(\Exception $e){
                        $form->get($fileField)->addError(new FormError($this->translator->trans('file.upload.unknown_error')));
                        $result = false;
                    }
                }

//                $setterMethod = $this->container->getParameter('melolab_biogestion_fileupload.mappings')['contract_digital_copy']['filename_setter'];
                $entity->$setterMethod($filename);
            }
        }


        return $result;
    }

    /**
     * Method copied from Vich\UploaderBundle\Handler\AbstractHandler::getMapping()
     * Access changed from protected to public.
     * @param $obj
     * @param $fieldName
     * @param null $className
     * @return null|\Vich\UploaderBundle\Mapping\PropertyMapping
     */
    public function getMapping($obj, $fieldName, $className = null)
    {
        $mapping = $this->propertyMappingFactory->fromField($obj, $fieldName, $className);

        if ($mapping === null) {
            throw new MappingNotFoundException(sprintf('Mapping not found for field "%s"', $fieldName));
        }

        return $mapping;
    }

    /**
     * Get the routing information of the referer.
     * Adapted from: https://www.strangebuzz.com/en/snippets/get-the-routing-information-of-the-referer
     *
     * @param RouterInterface $router
     */
    public function getRouteFromReferer(Request $request)
    {
        $referer = (string) $request->headers->get('referer'); // get the referer, it can be empty!

        // Empty referer
        if (!$referer) {
            return '';
        }

        $refererPathInfo = Request::create($referer)->getPathInfo();

        // Remove the scriptname if using a dev controller like app_dev.php (Symfony 3.x only)
        $refererPathInfo = str_replace($request->getScriptName(), '', $refererPathInfo);

        // try to match the path with the application routing
        $routeInfos = $this->router->match($refererPathInfo);

        // get the Symfony route name
        $refererRoute = $routeInfos['_route'] ?? '';

        return $refererRoute;
        // That's it!
    }

    /**
     * Return requested file as a StreamingResponse.
     * @param Request $request
     * @param int $id Entity id
     * @param string $mapping Mapping of the UploadBundle and VichUpload configuration
     */
    public function downloadActionHelper(Request $request, $id, $mapping) {
//        var_dump($this->get('vich_uploader.metadata_reader')->getUploadableFields(\Symfony\Component\Security\Core\Util\ClassUtils::getRealClass($lr)));
//        var_dump($this->container->getParameter('melolab_biogestion_fileupload.mappings'));
//        var_dump($this->container->getParameter('vich_uploader.mappings')); die();

        $mappings = $this->container->getParameter('melolab_biogestion_fileupload.mappings');
        $config = $mappings[$mapping];

        if (!$config) {
            throw new NotFoundHttpException($this->translator->trans('file.entity_not_found'));
        }

        $entity = $this->em->getRepository($config['entity'])->{$config['repository_method']}($id);

        if (!$entity) {
            throw new NotFoundHttpException($this->translator->trans('file.entity_not_found'));
        }

        // Security
//        var_dump($request->headers->get('referer'));
        if (false === $config['download_ignore_security']) {
            if (true === $config['allow_anonymous_downloads']) {
                if (true === $this->securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED') and false === $this->securityContext->isGranted('VIEW', $entity)) {
                    throw new AccessDeniedException();
                }
            } else {
                if (false === $this->securityContext->isGranted('VIEW', $entity)) {
                    throw new AccessDeniedException();
                }
            }
        }
        if (true === $config['download_ignore_security']) {
            $refererRoute = $this->container->get('upload.handler')->getRouteFromReferer($request);
//            var_dump($refererRoute);
//            var_dump($config['download_allowed_referer_routes']);
            if (!$refererRoute or !in_array($refererRoute, $config['download_allowed_referer_routes'])) {
                throw new AccessDeniedException();
            }
        }

        $mimeTypes = array(
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'exe' => 'application/octet-stream',
            'zip' => 'application/zip',
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'jpeg' => 'image/jpg',
            'jpg' => 'image/jpg',
            'php' => 'text/plain'
        );

        // Get filename
        $filename = $entity->{$config['filename_getter']}();

        if (!$filename) {
            throw new NotFoundHttpException($this->get('translator')->trans('file.file_not_found'));
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $uploadFolder = $this->container->getParameter('vich_uploader.mappings')[$config['vich_mapping']]['upload_destination'];

        // Full path to file
        $path = $uploadFolder."/".$filename;
//        var_dump($uploadFolder."/".$filename);
//        die;

        // Prepare the http response
        $response = new StreamedResponse();
        $response->setCallback(function() use ($path) {
            $fp = fopen($path, 'rb');
            fpassthru($fp);
        });
        $response->headers->set('Content-Type', $mimeTypes[$ext]);

        return $response;
    }
}
