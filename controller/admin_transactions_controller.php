<?php
/**
 *
 * PayPal Donation extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2015 Skouat
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace skouat\ppde\controller;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\language\language;
use phpbb\log\log;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;
use skouat\ppde\operators\transactions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @property config             config             Config object
 * @property ContainerInterface container          Service container interface
 * @property string             id_prefix_name     Prefix name for identifier in the URL
 * @property string             lang_key_prefix    Prefix for the messages thrown by exceptions
 * @property language           language           Language user object
 * @property log                log                The phpBB log system.
 * @property string             module_name        Name of the module currently used
 * @property request            request            Request object.
 * @property bool               submit             State of submit $_POST variable
 * @property template           template           Template object
 * @property string             u_action           Action URL
 * @property user               user               User object.
 */
class admin_transactions_controller extends admin_main
{
	public $ppde_operator;
	protected $adm_relative_path;
	protected $auth;
	protected $entry_count;
	protected $last_page_offset;
	protected $php_ext;
	protected $phpbb_admin_path;
	protected $phpbb_root_path;
	protected $table_prefix;
	protected $table_ppde_transactions;
	private $is_ipn_test = false;
	private $suffix_ipn;

	/**
	 * Constructor
	 *
	 * @param auth               $auth                       Authentication object
	 * @param config             $config                     Config object
	 * @param ContainerInterface $container                  Service container interface
	 * @param language           $language                   Language user object
	 * @param log                $log                        The phpBB log system
	 * @param transactions       $ppde_operator_transactions Operator object
	 * @param request            $request                    Request object
	 * @param template           $template                   Template object
	 * @param user               $user                       User object.
	 * @param string             $adm_relative_path          phpBB admin relative path
	 * @param string             $phpbb_root_path            phpBB root path
	 * @param string             $php_ext                    phpEx
	 * @param string             $table_prefix               The table prefix
	 * @param string             $table_ppde_transactions    Name of the table used to store data
	 *
	 * @access public
	 */
	public function __construct(auth $auth, config $config, ContainerInterface $container, language $language, log $log, transactions $ppde_operator_transactions, request $request, template $template, user $user, $adm_relative_path, $phpbb_root_path, $php_ext, $table_prefix, $table_ppde_transactions)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->container = $container;
		$this->language = $language;
		$this->log = $log;
		$this->ppde_operator = $ppde_operator_transactions;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->adm_relative_path = $adm_relative_path;
		$this->phpbb_admin_path = $phpbb_root_path . $adm_relative_path;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->table_prefix = $table_prefix;
		$this->table_ppde_transactions = $table_ppde_transactions;
		parent::__construct(
			'transactions',
			'PPDE_DT',
			'transaction'
		);
	}

	/**
	 * Display the transactions list
	 *
	 * @param string $id     Module id
	 * @param string $mode   Module categorie
	 * @param string $action Action name
	 *
	 * @return void
	 * @access public
	 */
	public function display_transactions($id, $mode, $action)
	{
		// Set up general vars
		$start = $this->request->variable('start', 0);
		$deletemark = $this->request->is_set('delmarked');
		$deleteall = $this->request->is_set('delall');
		$marked = $this->request->variable('mark', array(0));
		// Sort keys
		$sort_days = $this->request->variable('st', 0);
		$sort_key = $this->request->variable('sk', 't');
		$sort_dir = $this->request->variable('sd', 'd');

		// Initiate an entity
		/** @type \skouat\ppde\entity\transactions $entity */
		$entity = $this->get_container_entity();

		// Delete entries if requested and able
		if (($deletemark || $deleteall) && $this->auth->acl_get('a_ppde_manage'))
		{
			if (confirm_box(true))
			{
				$where_sql = '';

				if ($deletemark && count($marked))
				{
					$where_sql = $this->ppde_operator->build_marked_where_sql($marked);
				}

				if ($where_sql || $deleteall)
				{
					$entity->delete(0, '', $where_sql, $deleteall);
					$this->update_overview_stats();
					$this->update_overview_stats(true);
					$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_' . $this->lang_key_prefix . '_PURGED', time());
				}
			}
			else
			{
				confirm_box(false, $this->language->lang('CONFIRM_OPERATION'), build_hidden_fields(array(
						'start'     => $start,
						'delmarked' => $deletemark,
						'delall'    => $deleteall,
						'mark'      => $marked,
						'st'        => $sort_days,
						'sk'        => $sort_key,
						'sd'        => $sort_dir,
						'i'         => $id,
						'mode'      => $mode,
						'action'    => $action))
				);
			}
		}

		$this->do_action($action);

		if (!$action)
		{
			/** @type \phpbb\pagination $pagination */
			$pagination = $this->container->get('pagination');

			// Sorting
			$limit_days = array(0 => $this->language->lang('ALL_ENTRIES'), 1 => $this->language->lang('1_DAY'), 7 => $this->language->lang('7_DAYS'), 14 => $this->language->lang('2_WEEKS'), 30 => $this->language->lang('1_MONTH'), 90 => $this->language->lang('3_MONTHS'), 180 => $this->language->lang('6_MONTHS'), 365 => $this->language->lang('1_YEAR'));
			$sort_by_text = array('txn' => $this->language->lang('PPDE_DT_SORT_TXN_ID'), 'u' => $this->language->lang('PPDE_DT_SORT_DONORS'), 'ipn' => $this->language->lang('PPDE_DT_SORT_IPN_STATUS'), 'ipn_test' => $this->language->lang('PPDE_DT_SORT_IPN_TYPE'), 'ps' => $this->language->lang('PPDE_DT_SORT_PAYMENT_STATUS'), 't' => $this->language->lang('SORT_DATE'));
			$sort_by_sql = array('txn' => 'txn.txn_id', 'u' => 'u.username_clean', 'ipn' => 'txn.confirmed', 'ipn_test' => 'txn.test_ipn', 'ps' => 'txn.payment_status', 't' => 'txn.payment_date');

			$s_limit_days = $s_sort_key = $s_sort_dir = $u_sort_param = '';
			gen_sort_selects($limit_days, $sort_by_text, $sort_days, $sort_key, $sort_dir, $s_limit_days, $s_sort_key, $s_sort_dir, $u_sort_param);

			// Define where and sort sql for use in displaying transactions
			$sql_where = ($sort_days) ? (time() - ($sort_days * 86400)) : 0;
			$sql_sort = $sort_by_sql[$sort_key] . ' ' . (($sort_dir == 'd') ? 'DESC' : 'ASC');

			$keywords = $this->request->variable('keywords', '', true);
			$keywords_param = !empty($keywords) ? '&amp;keywords=' . urlencode(htmlspecialchars_decode($keywords)) : '';

			// Grab log data
			$log_data = array();
			$log_count = 0;

			$this->view_txn_log($log_data, $log_count, (int) $this->config['topics_per_page'], $start, $sql_where, $sql_sort, $keywords);

			$base_url = $this->u_action . '&amp;' . $u_sort_param . $keywords_param;
			$pagination->generate_template_pagination($base_url, 'pagination', 'start', $log_count, (int) $this->config['topics_per_page'], $start);

			$this->template->assign_vars(array(
				'S_CLEARLOGS'  => $this->auth->acl_get('a_ppde_manage'),
				'S_KEYWORDS'   => $keywords,
				'S_LIMIT_DAYS' => $s_limit_days,
				'S_SORT_KEY'   => $s_sort_key,
				'S_SORT_DIR'   => $s_sort_dir,
				'S_TXN'        => $mode,
				'U_ACTION'     => $this->u_action . '&amp;' . $u_sort_param . $keywords_param . '&amp;start=' . $start,
			));

			array_map(array($this, 'display_log_assign_template_vars'), $log_data);
		}
	}

	/**
	 * Updates the Overview module statistics
	 *
	 * @param bool $ipn_test
	 *
	 * @return void
	 * @access public
	 */
	public function update_overview_stats($ipn_test = false)
	{
		$this->set_ipn_test_properties($ipn_test);
		$this->config->set('ppde_anonymous_donors_count' . $this->suffix_ipn, $this->get_count_result('ppde_anonymous_donors_count' . $this->suffix_ipn));
		$this->config->set('ppde_known_donors_count' . $this->suffix_ipn, $this->get_count_result('ppde_known_donors_count' . $this->suffix_ipn), true);
		$this->config->set('ppde_transactions_count' . $this->suffix_ipn, $this->get_count_result('ppde_transactions_count' . $this->suffix_ipn), true);
	}

	/**
	 * @param int   $user_id
	 * @param float $amount
	 */
	public function update_user_stats($user_id, $amount)
	{
		if (!$user_id)
		{
			trigger_error($this->language->lang('EXCEPTION_INVALID_USER_ID', $user_id), E_USER_WARNING);
		}

		$this->ppde_operator->sql_update_user_stats($user_id, $amount);
	}

	/**
	 * Sets properties related to ipn tests
	 *
	 * @param bool $ipn_test
	 *
	 * @return void
	 * @access public
	 */
	public function set_ipn_test_properties($ipn_test)
	{
		$this->set_ipn_test($ipn_test);
		$this->set_suffix_ipn();
	}

	/**
	 * Sets the property $this->is_ipn_test
	 *
	 * @param $ipn_test
	 *
	 * @return void
	 * @access private
	 */
	private function set_ipn_test($ipn_test)
	{
		$this->is_ipn_test = $ipn_test ? (bool) $ipn_test : false;
	}

	/**
	 * Sets the property $this->suffix_ipn
	 *
	 * @return void
	 * @access private
	 */
	private function set_suffix_ipn()
	{
		$this->suffix_ipn = $this->is_ipn_test ? '_ipn' : '';
	}

	/**
	 * Returns count result for updating stats
	 *
	 * @param string $config_name
	 *
	 * @return int
	 * @access private
	 */
	private function get_count_result($config_name)
	{
		if (!$this->config->offsetExists($config_name))
		{
			trigger_error($this->language->lang('EXCEPTION_INVALID_CONFIG_NAME', $config_name), E_USER_WARNING);
		}

		return $this->ppde_operator->sql_query_count_result($config_name, $this->is_ipn_test);
	}

	/**
	 * Do action regarding the value of $action
	 *
	 * @param string $action Requested action
	 *
	 * @return void
	 * @access private
	 */
	private function do_action($action)
	{
		// Action: view
		if ($action == 'view')
		{
			// Request Identifier of the transaction
			$transaction_id = $this->request->variable('id', 0);

			// Initiate an entity
			/** @type \skouat\ppde\entity\currency $entity */
			$entity = $this->get_container_entity();

			// add field username to the table schema needed by entity->import()
			$additional_table_schema = array('item_username' => array('name' => 'username', 'type' => 'string'));

			// Grab transaction data
			$data_ary = $entity->get_data($this->ppde_operator->build_sql_data($transaction_id), $additional_table_schema);

			foreach ($data_ary as $data)
			{
				$this->template->assign_vars(array(
					'BOARD_USERNAME' => $data['username'],
					'EXCHANGE_RATE'  => '1 ' . $data['mc_currency'] . ' = ' . $data['exchange_rate'] . ' ' . $data['settle_currency'],
					'ITEM_NAME'      => $data['item_name'],
					'ITEM_NUMBER'    => $data['item_number'],
					'MC_CURRENCY'    => $data['net_amount'] . ' ' . $data['mc_currency'],
					'MC_GROSS'       => $data['mc_gross'] . ' ' . $data['mc_currency'],
					'MC_FEE'         => $data['mc_fee'] . ' ' . $data['mc_currency'],
					'MC_NET'         => $data['net_amount'] . ' ' . $data['mc_currency'],
					'MEMO'           => $data['memo'],
					'NAME'           => $data['first_name'] . ' ' . $data['last_name'],
					'PAYER_EMAIL'    => $data['payer_email'],
					'PAYER_ID'       => $data['payer_id'],
					'PAYER_STATUS'   => $data['payer_status'] ? $this->language->lang('PPDE_DT_VERIFIED') : $this->language->lang('PPDE_DT_UNVERIFIED'),
					'PAYMENT_DATE'   => $this->user->format_date($data['payment_date']),
					'PAYMENT_STATUS' => $this->language->lang(array('PPDE_DT_PAYMENT_STATUS_VALUES', strtolower($data['payment_status']))),
					'RECEIVER_EMAIL' => $data['receiver_email'],
					'RECEIVER_ID'    => $data['receiver_id'],
					'SETTLE_AMOUNT'  => $data['settle_amount'] . ' ' . $data['settle_currency'],
					'TXN_ID'         => $data['txn_id'],

					'L_PPDE_DT_SETTLE_AMOUNT'         => $this->language->lang('PPDE_DT_SETTLE_AMOUNT', $data['settle_currency']),
					'L_PPDE_DT_EXCHANGE_RATE_EXPLAIN' => $this->language->lang('PPDE_DT_EXCHANGE_RATE_EXPLAIN', $this->user->format_date($data['payment_date'])),
					'S_CONVERT'                       => ($data['settle_amount'] == 0 && empty($data['exchange_rate'])) ? false : true,
				));
			}

			$this->template->assign_vars(array(
				'U_ACTION' => $this->u_action,
				'U_BACK'   => $this->u_action,
				'S_VIEW'   => true,
			));
		}
	}

	/**
	 * View log
	 *
	 * @param array  &$log         The result array with the logs
	 * @param mixed  &$log_count   If $log_count is set to false, we will skip counting all entries in the
	 *                             database. Otherwise an integer with the number of total matching entries is returned.
	 * @param int    $limit        Limit the number of entries that are returned
	 * @param int    $offset       Offset when fetching the log entries, f.e. when paginating
	 * @param int    $limit_days
	 * @param string $sort_by      SQL order option, e.g. 'l.log_time DESC'
	 * @param string $keywords     Will only return log entries that have the keywords in log_operation or log_data
	 *
	 * @return int Returns the offset of the last valid page, if the specified offset was invalid (too high)
	 * @access private
	 */
	private function view_txn_log(&$log, &$log_count, $limit = 0, $offset = 0, $limit_days = 0, $sort_by = 'txn.payment_date DESC', $keywords = '')
	{
		$count_logs = ($log_count !== false);

		$log = $this->get_logs($count_logs, $limit, $offset, $limit_days, $sort_by, $keywords);
		$log_count = $this->get_log_count();

		return $this->get_valid_offset();
	}

	/**
	 * @param bool   $count_logs
	 * @param int    $limit
	 * @param int    $offset
	 * @param int    $log_time
	 * @param string $sort_by
	 * @param string $keywords
	 *
	 * @return array $log
	 * @access private
	 */
	private function get_logs($count_logs = true, $limit = 0, $offset = 0, $log_time = 0, $sort_by = 'txn.payment_date DESC', $keywords = '')
	{
		$this->entry_count = 0;
		$this->last_page_offset = $offset;
		$url_ary = array();

		if ($this->get_container_entity()->is_in_admin() && $this->phpbb_admin_path)
		{
			$url_ary['profile_url'] = append_sid($this->phpbb_admin_path . 'index.' . $this->php_ext, 'i=users&amp;mode=overview');
			$url_ary['txn_url'] = append_sid($this->phpbb_admin_path . 'index.' . $this->php_ext, 'i=-skouat-ppde-acp-ppde_module&amp;mode=transactions');

		}
		else
		{
			$url_ary['profile_url'] = append_sid($this->phpbb_root_path . 'memberlist.' . $this->php_ext, 'mode=viewprofile');
			$url_ary['txn_url'] = '';
		}

		$get_logs_sql_ary = $this->ppde_operator->get_logs_sql_ary($keywords, $sort_by, $log_time);

		if ($count_logs)
		{
			$this->entry_count = $this->ppde_operator->query_sql_count($get_logs_sql_ary, 'txn.transaction_id');

			if ($this->entry_count == 0)
			{
				// Save the queries, because there are no logs to display
				$this->last_page_offset = 0;

				return array();
			}

			// Return the user to the last page that is valid
			while ($this->last_page_offset >= $this->entry_count)
			{
				$this->last_page_offset = max(0, $this->last_page_offset - $limit);
			}
		}

		return $this->ppde_operator->build_log_ary($get_logs_sql_ary, $url_ary, $limit, $this->last_page_offset);
	}

	/**
	 * @return integer
	 */
	public function get_log_count()
	{
		return ($this->entry_count) ? (int) $this->entry_count : 0;
	}

	/**
	 * @return integer
	 */
	public function get_valid_offset()
	{
		return ($this->last_page_offset) ? (int) $this->last_page_offset : 0;
	}

	/**
	 * @return boolean
	 */
	public function get_ipn_test()
	{
		return ($this->is_ipn_test) ? (bool) $this->is_ipn_test : false;
	}

	/**
	 * @return string
	 */
	public function get_suffix_ipn()
	{
		return ($this->suffix_ipn) ? $this->suffix_ipn : '';
	}

	/**
	 * Set log output vars for display in the template
	 *
	 * @param array $row
	 *
	 * @return void
	 * @access protected
	 */
	protected function display_log_assign_template_vars($row)
	{
		$this->template->assign_block_vars('log', array(
			'TNX_ID'           => $row['txn_id'],
			'USERNAME'         => $row['username_full'],
			'DATE'             => $this->user->format_date($row['payment_date']),
			'ID'               => $row['transaction_id'],
			'S_TEST_IPN'       => (bool) $row['test_ipn'],
			'CONFIRMED'        => ($row['confirmed']) ? $this->language->lang('PPDE_DT_VERIFIED') : $this->language->lang('PPDE_DT_UNVERIFIED'),
			'PAYMENT_STATUS'   => $this->language->lang(array('PPDE_DT_PAYMENT_STATUS_VALUES', strtolower($row['payment_status']))),
			'S_CONFIRMED'      => (bool) $row['confirmed'],
			'S_PAYMENT_STATUS' => (strtolower($row['payment_status']) === 'completed') ? true : false,
		));
	}
}
