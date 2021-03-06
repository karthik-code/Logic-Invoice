<?php
defined('_PATH') or die('Restricted!');

class ControllerPaymentAuthorizeNetAimAuthorizeNetAim extends Controller {
    public function index() {
        $this->data = $this->load->language('payment/authorizenet_aim/authorizenet_aim');

        $this->load->model('billing/invoice');

        $invoice_info = $this->model_billing_invoice->getInvoice((int)$this->request->get['invoice_id'], $this->customer->getId());

        if ($invoice_info) {
			$this->data['invoice_id'] = $invoice_info['invoice_id'];
			
            $this->data['months'] = array();

			for ($i = 1; $i <= 12; $i++) {
				$this->data['months'][] = array(
					'text'  => strftime('%B', mktime(0, 0, 0, $i, 1, 2000)),
					'value' => sprintf('%02d', $i)
				);
			}

			$today = getdate();

			$this->data['year_expire'] = array();

			for ($i = $today['year']; $i < $today['year'] + 11; $i++) {
				$this->data['year_expire'][] = array(
					'text'  => strftime('%Y', mktime(0, 0, 0, 1, 1, $i)),
					'value' => strftime('%Y', mktime(0, 0, 0, 1, 1, $i))
				);
			}
			
			$this->response->setOutput($this->render('payment/authorizenet_aim/authorizenet_aim'));
        }
    }
	
	public function confirm() {
		$json = array();
		
		if (isset($this->request->get['invoice_id'])) {
            $invoice_id = (int)$this->request->get['invoice_id'];
        } else {
            $invoice_id = 0;
        }
		
		if ($this->config->get('authorizenet_aim_server') == 'live') {
			$url = 'https://secure.authorize.net/gateway/transact.dll';
		} elseif ($this->config->get('authorizenet_aim_server') == 'test') {
			$url = 'https://test.authorize.net/gateway/transact.dll';
		}

		$this->load->model('billing/invoice');

        $invoice_info = $this->model_billing_invoice->getInvoice($invoice_id, $this->customer->getId());

		if ($invoice_info) {
			if (!(in_array($invoice_info['status_id'], $this->config->get('config_pending_status')) || in_array($invoice_info['status_id'], $this->config->get('config_overdue_status')))) {
                $json['redirect'] = $this->url->link('account/invoice/invoice', 'invoice_id=' . $invoice_id, 'SSL');
            }
			
			if (!$json) {
				$data = array();

				$data['x_login'] = $this->config->get('authorizenet_aim_login');
				$data['x_tran_key'] = $this->config->get('authorizenet_aim_key');
				$data['x_version'] = '3.1';
				$data['x_delim_data'] = 'true';
				$data['x_delim_char'] = '|';
				$data['x_encap_char'] = '"';
				$data['x_relay_response'] = 'false';
				$data['x_first_name'] = html_entity_decode($invoice_info['payment_firstname'] ? $invoice_info['payment_firstname'] : $invoice_info['firstname'], ENT_QUOTES, 'UTF-8');
				$data['x_last_name'] = html_entity_decode($invoice_info['payment_lastname'] ? $invoice_info['payment_lastname'] : $invoice_info['lastname'], ENT_QUOTES, 'UTF-8');
				$data['x_company'] = html_entity_decode($invoice_info['payment_company'] ? $invoice_info['payment_company'] : $invoice_info['company'], ENT_QUOTES, 'UTF-8');
				$data['x_address'] = html_entity_decode($invoice_info['payment_address_1'], ENT_QUOTES, 'UTF-8');
				$data['x_city'] = html_entity_decode($invoice_info['payment_city'], ENT_QUOTES, 'UTF-8');
				$data['x_state'] = html_entity_decode($invoice_info['payment_zone'], ENT_QUOTES, 'UTF-8');
				$data['x_zip'] = html_entity_decode($invoice_info['payment_postcode'], ENT_QUOTES, 'UTF-8');
				$data['x_country'] = html_entity_decode($invoice_info['payment_country'], ENT_QUOTES, 'UTF-8');
				$data['x_customer_ip'] = $this->request->server['REMOTE_ADDR'];
				$data['x_email'] = $invoice_info['email'];
				$data['x_description'] = html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8');
				$data['x_amount'] = $this->currency->format($invoice_info['total'], $invoice_info['currency_code'], 1.00000, false);
				$data['x_currency_code'] = $invoice_info['currency_code'];
				$data['x_method'] = 'CC';
				$data['x_type'] = ($this->config->get('authorizenet_aim_method') == 'capture') ? 'AUTH_CAPTURE' : 'AUTH_ONLY';
				$data['x_card_num'] = str_replace(' ', '', $this->request->post['cc_number']);
				$data['x_exp_date'] = $this->request->post['cc_expire_date_month'] . $this->request->post['cc_expire_date_year'];
				$data['x_card_code'] = $this->request->post['cc_cvv2'];
				$data['x_invoice_num'] = $invoice_id;

				if ($this->config->get('authorizenet_aim_mode') == 'test') {
					$data['x_test_request'] = 'true';
				}

				$curl = curl_init($url);

				curl_setopt($curl, CURLOPT_PORT, 443);
				curl_setopt($curl, CURLOPT_HEADER, 0);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
				curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
				curl_setopt($curl, CURLOPT_POST, 1);
				curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($curl, CURLOPT_TIMEOUT, 10);
				curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));

				$response = curl_exec($curl);

				if (curl_error($curl)) {
					$json['error'] = 'CURL ERROR: ' . curl_errno($curl) . '::' . curl_error($curl);

					$this->log->write('AUTHORIZE.NET AIM CURL ERROR: ' . curl_errno($curl) . '::' . curl_error($curl));
				} elseif ($response) {
					$i = 1;

					$response_info = array();

					$results = explode('|', $response);

					foreach ($results as $result) {
						$response_info[$i] = trim($result, '"');

						$i++;
					}

					if ($response_info[1] == '1') {
						$message = '';

						if (isset($response_info['5'])) {
							$message .= 'Authorization Code: ' . $response_info['5'] . "\n";
						}

						if (isset($response_info['6'])) {
							$message .= 'AVS Response: ' . $response_info['6'] . "\n";
						}

						if (isset($response_info['7'])) {
							$message .= 'Transaction ID: ' . $response_info['7'] . "\n";
						}

						if (isset($response_info['39'])) {
							$message .= 'Card Code Response: ' . $response_info['39'] . "\n";
						}

						if (isset($response_info['40'])) {
							$message .= 'Cardholder Authentication Verification Response: ' . $response_info['40'] . "\n";
						}

						if (!$this->config->get('authorizenet_aim_hash') || (strtoupper($response_info[38]) == strtoupper(md5($this->config->get('authorizenet_aim_hash') . $this->config->get('authorizenet_aim_login') . $response_info[7] . $this->currency->format($invoice_info['total'], $invoice_info['currency_code'], 1.00000, false))))) {
							$status_id = $this->config->get('authorizenet_aim_completed_status_id');
						} else {
							$status_id = $this->config->get('authorizenet_aim_denied_status_id');
							
							$message .= 'Payment MD5 Hash comparison failed.' . "\n";
						}
						
						$data = array(
							'status_id' => $status_id,
							'comment'   => $message
						);

						$this->model_billing_invoice->addHistory($invoice_id, $data, true);
						
						$this->load->model('system/status');

						$status = $this->model_system_status->getStatus($status_id);

						$this->load->model('system/activity');

						$this->model_system_activity->addActivity(sprintf($this->language->get('text_updated'), $invoice_id, $status['name']));

						$json['redirect'] = $this->url->link('account/invoice/success', 'invoice_id=' . $invoice_id, 'SSL');
					} else {
						$json['error'] = $response_info[4];
					}
				} else {
					$json['error'] = 'Empty Gateway Response';

					$this->log->write('AUTHORIZE.NET AIM CURL ERROR: Empty Gateway Response');
				}

				curl_close($curl);
			}
		} else {
			$json['redirect'] = $this->url->link('account/invoice', '', 'SSL');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}