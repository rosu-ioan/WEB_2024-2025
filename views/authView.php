<?php

require_once __DIR__ . '/AbstractView.php';

class AuthView extends AbstractView {
    
   
    protected function handleHtmlResponse($data) {  
        if (isset($data['success']) && $data['success'] && isset($data['redirect'])) {
            header('Location: ' . $data['redirect']);
            exit;
        }
        
        $templateData = [
            'page_title' => 'Sign In - nor.ust',
            'base_url' => $this->getBaseUrl()
        ];
        
        return $this->renderHtml('auth.html', $templateData);  
    }
}