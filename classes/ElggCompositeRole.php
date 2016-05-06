<?php

/**
 * Composite role that combines permissions of multiple roles
 */
class ElggCompositeRole extends ElggRole {

	/**
	 * Protected permissions metadata
	 * @var string
	 */
	protected $permissions;

	/**
	 * Protected extends metdata
	 * @var string[]
	 */
	protected $extends;

	/**
	 * Partial roles
	 * @var ElggRole[]
	 */
	protected $parts;

	/**
	 * Set partial roles
	 * @param ElggRole[] $roles
	 * @return void
	 */
	public function setParts(array $roles = []) {
		$this->parts = $roles;
		$title = [];
		$name = [];
		foreach ($this->parts as $part) {
			$title[] = $part->title;
			$name[] = $part->getDisplayName();
		}
		$this->title = implode('::', $title);
		$this->name = implode(', ', $name);
	}

	/**
	 * Get partial roles ordered by priority
	 * Roles that were assigned earlier take precedence over roles assigned later
	 * Comparison is done by the time relationship was created
	 * @return ElggRole[]
	 */
	public function getParts() {
		$parts = $this->parts;
		uasort($parts, function($role1, $role2) {
			$role1_time = (int) $role1->getVolatileData('select:relationship_time');
			$role2_time = (int) $role2->getVolatileData('select:relationship_time');
			if ($role1_time == $role2_time) {
				return 0;
			}
			return ($role1_time < $role2_time) ? -1 : 1;
		});
		return $parts;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDisplayName() {
		return $this->name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setPermissions($permissions = array()) {
		throw new LogicException('Can not set permissions for a composite role object');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPermissions() {
		return [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function setExtends($extends = array()) {
		throw new LogicException('Can not set extensions for a composite role object');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getExtends() {
		$parts = $this->getParts();
		$extends = [];
		foreach ($parts as $part) {
			$extends[] = $part->name;
		}
		return array_reverse($extends);
	}

	/**
	 * {@inheritdoc}
	 */
	public function save() {
		throw new LogicException('Can not save composite role object');
	}

	/**
	 * {@inheritdoc}
	 */
	public function matches($role_name) {
		foreach ($this->getParts() as $part) {
			if ($part->matches($role_name)) {
				return true;
			}
		}
		return false;
	}

}
