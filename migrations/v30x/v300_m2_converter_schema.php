<?php
/**
 *
 * PayPal Donation extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2015 Skouat
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace skouat\ppde\migrations\v30x;

/**
 * This migration removes old schema from 3.0
 * installations of PayPal Donation MOD.
 */
class v300_m2_converter_schema extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\skouat\ppde\migrations\v30x\v300_m1_converter_data');
	}

	/**
	 * Run migration if donation_item table exists
	 *
	 * @return bool
	 */
	public function effectively_installed()
	{
		return !$this->db_tools->sql_table_exists($this->table_prefix . 'donation_item');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'donation_item',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function revert_schema()
	{
		// Do not revert the table because it requires a complete reinstall of PPDM for phpBB 3.0
		return array();
	}
}
