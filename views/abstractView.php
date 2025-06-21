<?php

require_once __DIR__ . '/../utils/autoload.php';
use Dotenv\Dotenv;

abstract class AbstractView {
    protected $templatePath;
    protected $baseUrl;
    
    public function __construct() {
        $this->templatePath = __DIR__ . '/../templates/';

        $dotenv = Dotenv::createImmutable(__DIR__ . '/../credentials');
        $dotenv->load();
        $this->baseUrl = $_ENV['APP_BASE_URL']; 
    }
    

    public function renderResponse($data, $isApiRequest) {
        if ($isApiRequest) {
            return $this->renderJson($data);
        } else {
            return $this->handleHtmlResponse($data);  
        }
    }
    
   
    protected function renderJson($data) {
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = date('Y-m-d H:i:s');
        }
        
        return json_encode($data, JSON_PRETTY_PRINT);
    }
    
   
    abstract protected function handleHtmlResponse($data);  
   
    protected function renderHtml($templateName, $templateData, $escapeHtml = true) {
        $template = $this->loadTemplate($templateName);
        return $this->renderTemplate($template, $templateData, $escapeHtml);
    }

    protected function loadTemplate($templateName) {
        $filePath = $this->templatePath . $templateName;
        
        if (!file_exists($filePath)) {
            throw new Exception("Template not found: $templateName at $filePath");
        }
        
        return file_get_contents($filePath);
    }
    
    
    protected function renderTemplate($template, $data, $escapeHtml = true) {
        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            
            if ($escapeHtml) {
                $template = str_replace($placeholder, $this->escapeHtml($value), $template);
            } else {
                $template = str_replace($placeholder,$this->convertToString($value), $template);
            }
        }
        
        $template = preg_replace('/\{\{[^}]+\}\}/', '', $template);
        
        return $template;
    }
    
   
    public function renderError($code, $message) {
        http_response_code($code);
        
        $templateData = [
            'error_code' => $code,
            'error_message' => $message
        ];
        
        return $this->renderHtml('error.html', $templateData);  
    }
    
   
    protected function escapeHtml($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    protected function convertToString($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);  
        } elseif ($value === null) {
            return '';
        }
        return (string) $value;
    }

    public function getBaseUrl() {
        return $this->baseUrl;
    }

    public static function getStaticBaseUrl() {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../credentials');
        $dotenv->load();

        return $_ENV['APP_BASE_URL'];
    }
}