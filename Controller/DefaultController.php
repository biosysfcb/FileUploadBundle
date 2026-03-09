<?php

namespace MeloLab\BioGestion\FileUploadBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Zend\Stdlib\Request;
use Zend\Stdlib\Response;

/**
 * Default controller of this bundle.
 * 
 * @author Andreas Schueller <aschueller@bio.puc.cl>
 */
class DefaultController extends Controller
{
    
    /**
     * Download attached files
     * @Route("/download/{id}/{mapping}", name="biogestion_fileupload_download")
     */
    public function downloadAction($id, $mapping) {
//        var_dump($this->get('vich_uploader.metadata_reader')->getUploadableFields(\Symfony\Component\Security\Core\Util\ClassUtils::getRealClass($lr)));
//        var_dump($this->container->getParameter('melolab_biogestion_fileupload.mappings'));
//        var_dump($this->container->getParameter('vich_uploader.mappings')); die();
        
        $mappings = $this->container->getParameter('melolab_biogestion_fileupload.mappings');
        $config = $mappings[$mapping];

        $entity = $this->getDoctrine()->getManager()->getRepository($config['entity'])->{$config['repository_method']}($id);

        if (!$entity) {
            throw $this->createNotFoundException($this->get('translator')->trans('file.entity_not_found'));
        }
        
        // Security
        if (true === $config['allow_anonymous_downloads']) {
            if (true === $this->get('security.context')->isGranted('IS_AUTHENTICATED_REMEMBERED') and false === $this->get('security.context')->isGranted('VIEW', $entity)) {
                throw new AccessDeniedException();            
            }
        } else {
            if (false === $this->get('security.context')->isGranted('VIEW', $entity)) {
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
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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
            throw $this->createNotFoundException($this->get('translator')->trans('file.file_not_found'));
        }
        
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $uploadFolder = $this->container->getParameter('vich_uploader.mappings')[$config['vich_mapping']]['upload_destination'];
        
        // Full path to file
        $path = $uploadFolder."/".$filename;
        
        // Prepare the http response
        $response = new StreamedResponse();
        $response->setCallback(function() use ($path) {
            $fp = fopen($path, 'rb');
            fpassthru($fp);
        });
        $response->headers->set('Content-Type', $mimeTypes[$ext]); 

        return $response;
    }

    /**
     * Download attached files
     * @Route("/temp/download/{filename}/{mapping}", name="biogestion_fileupload_download_temp")
     */
    public function downloadTemporaryFilesAction($filename, $mapping) {
//        var_dump($this->get('vich_uploader.metadata_reader')->getUploadableFields(\Symfony\Component\Security\Core\Util\ClassUtils::getRealClass($lr)));
//        var_dump($this->container->getParameter('melolab_biogestion_fileupload.mappings'));
//        var_dump($this->container->getParameter('vich_uploader.mappings')); die();

//        $mappings = $this->container->getParameter('melolab_biogestion_fileupload.mappings');
//        $config = $mappings[$mapping];

//        $entity = new $config['entity'];

//        // Security
//        if (true === $config['allow_anonymous_downloads']) {
//            if (true === $this->get('security.context')->isGranted('IS_AUTHENTICATED_REMEMBERED') and false === $this->get('security.context')->isGranted('VIEW', $entity)) {
//                throw new AccessDeniedException();
//            }
//        } else {
//            if (false === $this->get('security.context')->isGranted('VIEW', $entity)) {
//                throw new AccessDeniedException();
//            }
//        }

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


        if (!$filename) {
            throw $this->createNotFoundException($this->get('translator')->trans('file.file_not_found'));
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $uploadFolder = $this->container->getParameter('melolab_biogestion_fileupload.temp_files_path');

        // Full path to file
        $path = $uploadFolder."/".$filename;

        // Prepare the http response
        $response = new StreamedResponse();
        $response->setCallback(function() use ($path) {
            $fp = fopen($path, 'rb');
            fpassthru($fp);
        });
        $response->headers->set('Content-Type', $mimeTypes[$ext]);

        return $response;
    }

    /**
     * Download attached files
     * @Route("/deletefile", name="biogestion_fileupload_delete", options={"expose"=true})
     */
    public function deleteFileAction(\Symfony\Component\HttpFoundation\Request $request){

        $data=array();

        $form = $this->createDeleteForm();

        $form->handleRequest($request);
        $mapping = $form->get('mapping')->getData();

        if($form->isValid()){
//            var_dump($form->get('mapping')->getData());
//            var_dump($form->get('temp_filename')->getData());

            $entityClass = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['entity'];
            $fileField = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['file_field'];
            $fileSetterMethod = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['file_setter'];
            $fileNameSetterMethod = $this->container->getParameter('melolab_biogestion_fileupload.mappings')[$mapping]['filename_setter'];

            if($form->get('temp_filename')->getData() !== null){

                $tempFolder = $this->container->getParameter('melolab_biogestion_fileupload.temp_files_path');
                $filename = $form->get('temp_filename')->getData();

                try{
                    $fs = new Filesystem();
                    $fs->remove($tempFolder.'/'.$filename);

                    if($fs->exists($tempFolder.'/'.$filename)){
                        $data['ok'] = false;
                        $data['error_message'] = $this->get('translator')->trans('file.upload.delete_error').'1';
                    }else{
                        $data['ok'] = true;
                        $data['temp_file_field'] = $fileField.'_fileuploadtemp';
                    }
                } catch(\Exception $e){
                    $data['ok'] = false;
                    $data['error_message'] = $this->get('translator')->trans('file.upload.delete_error').'2';
                }



            }
            else{
                $entity=$this->getDoctrine()->getRepository($entityClass)->findOneById($form->get('eid')->getData());

                try{
                    $this->get('vich_uploader.upload_handler')->remove($entity, $fileField);

                    $entity->$fileSetterMethod(null);
                    $entity->$fileNameSetterMethod(null);
                    $this->getDoctrine()->getManager()->persist($entity);
                    $this->getDoctrine()->getManager()->flush();

                    $data['ok'] = true;
                } catch(\Exception $e){
                    $data['ok'] = false;
                    $data['error_message'] = $this->get('translator')->trans('file.upload.delete_error').'3';
                }


            }
        }else{
            $data['ok'] = false;
            $data['error_message'] = $this->get('translator')->trans('file.upload.delete_error').'4';
        }

        return new \Symfony\Component\HttpFoundation\Response(json_encode($data), 200, array('Content-Type' => 'application/json'));
    }
    /**
     * Download attached files
     * @Route("/deleteform/{mapping}/{id}", defaults={"id" = 0}, name="biogestion_fileupload_get_delete_form", options={"expose"=true})
     */
    public function getDeleteFileForm($mapping, $id=0){

        $form = $this->createDeleteForm($mapping, $id);
        $render = $this->container->get('templating')->render(
            'MeloLabBioGestionFileUploadBundle:Form:delete-form.html.twig',
            array(
                'delete_file_form' => $form->createView(),
                'file_mapping' => $mapping,
            )
        );

        return new \Symfony\Component\HttpFoundation\Response(json_encode(array('render'=>$render)), 200, array('Content-Type' => 'application/json'));
    }

    /**
     * Creates a simple form with hidden field that represents an object ID
     * @param type $id
     * @return type
     */
    private function createDeleteForm($mapping='', $id=0)
    {
        return $this->container->get('form.factory')->createBuilder('form', array('mapping'=>$mapping, 'eid'=>$id))
            ->add('temp_filename', 'hidden')
            ->add('mapping', 'hidden')
            ->add('eid', 'hidden')
            ->getForm()
            ;
    }
}
