<?php

abstract class AbstractController {
    protected $model;
    protected $view;
    protected $isApiRequest;

    public function __construct($isApiRequest) {
        $nameModel = str_replace("Controller", "Model", get_class($this));
        $nameView = str_replace("Controller", "View", get_class($this));

        $this->model = new $nameModel;
        $this->view = new $nameView;
        $this->isApiRequest = $isApiRequest;
    }

    public abstract function executeAction($action, $params);

    protected function getBasePath() {
        return $this->view->getBaseUrl();
    }
}