<?php

class HomeController extends Controller {
    public function index() {
        $data = [
            'titre' => 'Horizon - Tutorat & Repétitions à Domicile'
        ];
        
        $this->view('home/index', $data);
    }
}