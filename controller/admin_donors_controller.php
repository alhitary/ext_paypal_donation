<?php
/**
 *
 * PayPal Donation extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017 Skouat
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace skouat\ppde\controller;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class admin_donors_controller
 *
 * @property \phpbb\config\config     config             Config object
 * @property ContainerInterface       container          Service container interface
 * @property \phpbb\request\request   request            Request object.
 * @property \phpbb\template\template template           Template object
 *
 * @package skouat\ppde\controller
 */
class admin_donors_controller extends admin_main
{
	protected $ppde_entity_transactions;
	protected $ppde_main_controller;
	protected $ppde_operator_transactions;

	/**
	 * constructor
	 *
	 * @param \phpbb\config\config                    $config                     Config object
	 * @param ContainerInterface                      $container                  Service container interface
	 * @param \skouat\ppde\entity\transactions        $ppde_entity_transactions   Entity object
	 * @param \skouat\ppde\controller\main_controller $ppde_main_controller       Main controller object
	 * @param \skouat\ppde\operators\transactions     $ppde_operator_transactions Operator object
	 * @param \phpbb\request\request                  $request                    Request object
	 * @param \phpbb\template\template                $template                   Template object
	 */
	public function __construct(\phpbb\config\config $config, ContainerInterface $container, \skouat\ppde\entity\transactions $ppde_entity_transactions, \skouat\ppde\controller\main_controller $ppde_main_controller, \skouat\ppde\operators\transactions $ppde_operator_transactions, \phpbb\request\request $request, \phpbb\template\template $template)
	{
		$this->config = $config;
		$this->container = $container;
		$this->ppde_entity_transactions = $ppde_entity_transactions;
		$this->ppde_main_controller = $ppde_main_controller;
		$this->ppde_operator_transactions = $ppde_operator_transactions;
		$this->request = $request;
		$this->template = $template;
		parent::__construct(
			'donors',
			'PPDE_DD',
			''
		);
	}

	/**
	 * Display the donors list
	 *
	 * @return void
	 * @access public
	 */
	public function display_donors()
	{
		$start = $this->request->variable('start', 0);

		// Build SQL Query
		$get_donorlist_sql_ary = $this->ppde_operator_transactions->get_sql_donorlist_ary(false);
		// adds fields to the table schema needed by entity->import()
		$additional_table_schema = array(
			'item_username'    => array('name' => 'username', 'type' => 'string'),
			'item_user_colour' => array('name' => 'user_colour', 'type' => 'string'),
			'item_amount'      => array('name' => 'amount', 'type' => 'float'),
			'item_max_txn_id'  => array('name' => 'max_txn_id', 'type' => 'integer'),
		);

		// Get the donors list
		$data_ary = $this->ppde_entity_transactions->get_data($this->ppde_operator_transactions->build_sql_donorlist_data($get_donorlist_sql_ary), $additional_table_schema, $this->config['topics_per_page'], $start);

		// Get default currency data from the database
		$default_currency_data = $this->get_last_element($this->ppde_main_controller->get_default_currency_data($this->config['ppde_default_currency']));

		// Build pagination
		/** @type \phpbb\pagination $pagination */
		$pagination = $this->container->get('pagination');
		$pagination_url = append_sid($this->u_action);
		$total_donors = $this->ppde_operator_transactions->query_sql_count($get_donorlist_sql_ary, 'txn.user_id');
		$start = $pagination->validate_start($start, $this->config['topics_per_page'], $total_donors);
		$pagination->generate_template_pagination($pagination_url, 'pagination', 'start', $total_donors, $this->config['topics_per_page'], $start);

		foreach ($data_ary as $data)
		{
			$get_last_transaction_sql_ary = $this->ppde_operator_transactions->get_sql_donorlist_ary($data['max_txn_id']);
			$last_donation_data = $this->get_last_element($this->ppde_entity_transactions->get_data($this->ppde_operator_transactions->build_sql_donorlist_data($get_last_transaction_sql_ary)));

			$this->template->assign_block_vars('donorrow', array(
				'PPDE_DD_DONATED_AMOUNT' => $this->ppde_main_controller->currency_on_left(number_format($data['amount'], 2), $default_currency_data['currency_symbol'], (bool) $default_currency_data['currency_on_left']),
				'PPDE_DD_LAST_DONATION'  => $this->ppde_main_controller->currency_on_left(number_format($last_donation_data['mc_gross'], 2), $default_currency_data['currency_symbol'], (bool) $default_currency_data['currency_on_left']),
				'PPDE_DD_USERNAME'       => get_username_string('full', $data['user_id'], $data['username'], $data['user_colour']),
			));
		}
	}
}
