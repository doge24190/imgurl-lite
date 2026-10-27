<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Token lifecycle is available only through the authenticated, CSRF-protected admin UI. */
class Tokens extends CI_Controller {
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('basic');
        $this->load->library('basic');
        $this->basic->is_login(true);
        $this->load->library('api_tokens');
        $this->output->set_header('Cache-Control: no-store')->set_header('Referrer-Policy: no-referrer');
    }

    public function index()
    {
        if ($this->input->method() !== 'get') { header('Allow: GET'); show_error('请使用 GET。', 405); return; }
        $this->render();
    }

    public function create()
    {
        require_post();
        try {
            $created = $this->api_tokens->create($this->input->post('name'), $this->input->post('scopes'), $this->input->post('days'));
            $this->render($created['secret'], '令牌已创建。请立即复制保存，完整令牌只在本次响应中显示。');
        } catch (InvalidArgumentException $error) {
            $this->render(null, $error->getMessage(), 400);
        } catch (Throwable $error) {
            log_message('error', 'API token creation failed ('.get_class($error).').');
            show_error('无法保存 API 令牌，请检查数据库写入权限。', 503);
        }
    }

    public function revoke()
    {
        require_post();
        try {
            $this->api_tokens->revoke($this->input->post('id'));
            $this->render(null, '令牌已撤销，后续请求立即失效。');
        } catch (InvalidArgumentException $error) {
            $this->render(null, $error->getMessage(), 400);
        } catch (Throwable $error) {
            log_message('error', 'API token revocation failed ('.get_class($error).').');
            show_error('无法撤销 API 令牌，请检查数据库写入权限。', 503);
        }
    }

    private function render(?string $secret = null, string $message = '', int $status = 200): void
    {
        $data = ['admin_title' => 'API 令牌', 'tokens' => $this->api_tokens->listing(),
            'scopes' => Api_tokens::SCOPES, 'secret' => $secret, 'message' => $message];
        $this->output->set_status_header($status);
        $this->load->view('admin/header', $data);
        $this->load->view('admin/left');
        $this->load->view('admin/tokens', $data);
        $this->load->view('admin/footer');
    }
}
