<?php

namespace Elgg\Roles;

class Db implements \Elgg\Roles\DbInterface {

	public function getRoleByName($role_name = '') {
		$options = array(
			'type' => 'object',
			'subtype' => 'role',
			'metadata_name_value_pairs' => array(
				'name' => 'name',
				'value' => $role_name,
				'operand' => '=',
			),
			'limit' => 1,
		);
		$role_array = elgg_get_entities_from_metadata($options);
		return $role_array ? $role_array[0] : false;
	}
}