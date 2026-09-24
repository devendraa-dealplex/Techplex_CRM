<?php

defined('BASEPATH') or exit('No direct script access allowed');

include_once(__DIR__ . '/App_pdf.php');

class Contract_pdf extends App_pdf
{
    protected $contract;

    private $contract_number;

    public function __construct($contract)
    {
        $this->load_language($contract->client);
        $contract                = hooks()->apply_filters('contract_html_pdf_data', $contract);
        $GLOBALS['contract_pdf'] = $contract;

        parent::__construct();

        $this->contract        = $contract;
        $this->contract_number = format_contract_number($contract->id);
        $this->SetTitle($this->contract_number . ' - ' . $this->contract->subject);

        # Don't remove these lines - important for the PDF layout
        $this->contract->content = $this->fix_editor_html($this->contract->content);
    }

    public function prepare()
    {
        $this->set_view_vars([
            'contract' => $this->contract,
            'number'   => $this->contract_number,
        ]);

        return $this->build();
    }

    public function Output($name = 'doc.pdf', $dest = 'I')
    {
        $custom = hooks()->apply_filters('contract_pdf_custom_document', null, $this->contract);

        if ($custom) {
            return $custom->Output($name, $dest);
        }

        return parent::Output($name, $dest);
    }

    protected function type()
    {
        return 'contract';
    }

    protected function file_path()
    {
        $customPath = APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_contractpdf.php';
        $actualPath = APPPATH . 'views/themes/' . active_clients_theme() . '/views/contractpdf.php';

        if (file_exists($customPath)) {
            $actualPath = $customPath;
        }

        return $actualPath;
    }
}
