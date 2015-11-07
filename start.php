<?php

/**
 *
 * Roles plugin
 *
 * @package Roles
 * @author Andras Szepeshazi
 * @copyright Arck Interactive, LLC 2012
 * @link http://www.arckinteractive.com/
 */
require_once __DIR__ . '/autoloader.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/config.php';

/**
 * Default role constants definitions
 */
define('DEFAULT_ROLE', \Elgg\Roles\Api::DEFAULT_ROLE);
define('ADMIN_ROLE', \Elgg\Roles\Api::ADMIN_ROLE);
define('VISITOR_ROLE', \Elgg\Roles\Api::VISITOR_ROLE);
define('NO_ROLE', \Elgg\Roles\Api::NO_ROLE);

/**
 * Register Roles plugin's init function
 */
elgg_register_event_handler('init', 'system', 'roles_init');

/**
 * Initializes the Roles plugin
 * @return void
 */
function roles_init() {

	elgg_extend_view('forms/useradd', 'roles/useradd');

	// Provides default roles by own handler. This should be extended by site specific handlers
	elgg_register_plugin_hook_handler('roles:config', 'role', 'roles_get_roles_config');

	// Catch all actions and page route requests
	elgg_register_plugin_hook_handler('action', 'all', 'roles_actions_permissions');
	elgg_register_plugin_hook_handler('route', 'all', 'roles_pages_permissions');

	// Due to dynamically created (or extended) menus, we need to catch all 'register' hooks _after_ other modules added/removed their menu items
	elgg_register_plugin_hook_handler('register', 'all', 'roles_menus_permissions', 9999);

	// Check for role configuration updates
	if (elgg_is_admin_logged_in()) { // @TODO think through if this should rather be a role-based permission
		run_function_once('roles_update_100_to_101');
		elgg_register_event_handler('ready', 'system', 'roles_check_update', 1);
	}
	
	// Set up role-specific views, hooks and events, after all plugins are initialized
	elgg_register_event_handler('ready', 'system', 'roles_hooks_permissions', 9999);
	elgg_register_event_handler('ready', 'system', 'roles_events_permissions', 9999);
	elgg_register_event_handler('ready', 'system', 'roles_register_views', 9999);

	elgg_register_event_handler('create', 'user', 'roles_create_user');
}

/**
 * Processes view permissions from the role configuration array. This is called after the 'ready', 'system' event.
 *
 * For view extension and replacements the function simply calls the corresponding {@link elgg_extend_view()} and
 * {@link elgg_set_view_location()} functions, to post-register views after all plugins have been initalized.
 *
 * For suppressing views (by using the "deny" rule), it registers a specific handler for the given view,
 * to return an empty string instead of the view's original output. This is to conserve resources -
 * there are hundreds of views contributing to any elgg page. Listening for all "views", "all" hooks would
 * be quite a waste.
 *
 * @param string $event      "ready"
 * @param string $event_type "system"
 * @param mixed  $object     Not in use for this specific listener
 * @return void
 */
function roles_register_views($event, $event_type, $object) {
	$role = roles_get_role();
	if (!$role instanceof \ElggRole) {
		return;
	}
	return roles()->setupViews($role);
}

/**
 * A hook handler registered by {@link roles_register_views()} to suppress the outputs of certain views defined by
 * the role configuration array.
 *
 * @param string $hook_name    "view"
 * @param string $type         The view name
 * @param mixed  $return_value The original view output
 * @param mixed  $params       An associative array of parameters provided by the hook trigger
 * @return string An empty string to suppress the output of the original view
 * @deprecated 2.0
 */
function roles_views_permissions($hook_name, $type, $return_value, $params) {
	return roles()->supressView();
}

/**
 * Processes action permissions from the role configuration array. This is  called u pon each action execution.
 *
 * @param string  $hook_name    "action"
 * @param string  $action       The registered action name
 * @param boolean $return_value Return value
 * @param mixed   $params       An associative array of parameters provided by the hook trigger
 * @return boolean|void True if the action should be executed, false if it should be stopped
 */
function roles_actions_permissions($hook_name, $action, $return_value, $params) {

	$role = roles_get_role();
	if (!$role instanceof \ElggRole) {
		return;
	}

	$result = roles()->actionGatekeeper($role, $action);
	if ($result === false) {
		register_error(elgg_echo('roles:action:denied'));
	}

	return $result;
}

/**
 * Processes menu permissions from the role configuration array. This is called upon each "register" triggered hook.
 *
 * @param string  $hook_name    "register"
 * @param string  $type         The triggered "register" hook's type
 * @param boolean $return_value Return value
 * @param mixed   $params       An associative array of parameters provided by the hook trigger
 * @return void
 */
function roles_menus_permissions($hook_name, $type, $return_value, $params) {

	$updated_menu = $return_value;

	// Ignore all triggered hooks except for 'menu:menu_name' type
	list($hook_type, $prepared_menu_name) = explode(':', $type);

	if ($hook_type !== 'menu' || empty($prepared_menu_name)) {
		return;
	}

	$role = roles_get_role();
	if (!$role instanceof ElggRole) {
		return;
	}

	$role_perms = roles_get_role_permissions($role, 'menus');
	foreach ($role_perms as $menu => $perm_details) {

		$menu_parts = explode('::', $menu);
		$menu_name = isset($menu_parts[0]) ? $menu_parts[0] : "";

		// Check if this rule relates to the currently triggered menu and if we're in the right context for the current rule
		if (!roles_check_context($perm_details)) {
			continue;
		}
		// Try to act on this permission rule
		switch ($perm_details['rule']) {
			case 'deny':
				$updated_menu = roles_unregister_menu_item_recursive($updated_menu, $menu, $prepared_menu_name);
				break;
			case 'extend':
				if ($menu_name === $prepared_menu_name) {
					$menu_item = roles_prepare_menu_vars($perm_details['menu_item']);
					$menu_obj = ElggMenuItem::factory($menu_item);
					elgg_register_menu_item($menu_name, $menu_obj);
					$updated_menu = roles_get_menu($menu_name);
				}
				break;
			case 'replace':
				$menu_item = roles_prepare_menu_vars($perm_details['menu_item']);
				$menu_obj = ElggMenuItem::factory($menu_item);
				$updated_menu = roles_replace_menu_item_recursive($updated_menu, $menu, $prepared_menu_name, $menu_obj);
				break;
			case 'allow':
			default:
				break;
		}
	}
	// Return the updated menu to the hook triggering function (elgg_view_menu)
	return $updated_menu;
}

/**
 * Processes page permissions from the role configuration array. This is called upon each "route" triggered hook.
 *
 * @param string $hook   "route"
 * @param string $type   The triggered "register" hook's type
 * @param array  $route  'identifier' and 'segments'
 * @return void
 */
function roles_pages_permissions($hook, $type, $route) {
	$role = roles_get_role();
	if (!$role instanceof ElggRole) {
		return;
	}

	$segments = (array) elgg_extract('segments', $route, array());
	$identifier = elgg_extract('identifier', $route, elgg_extract('handler', $route));
	array_unshift($segments, $identifier);

	$result = roles()->pageGatekeeper($role, implode('/', $identifier));

	$error = elgg_extract('error', $result);
	$forward = elgg_extract('forward', $result);

	if ($error) {
		register_error(elgg_echo('roles:page:denied'));
	}
	if ($forward) {
		forward($forward);
	}
}

/**
 * Processes hook permissions from the role configuration array. Triggered by the 'ready','system' event.
 * This is to make sure that all plugins' init functions have been executed, and all hook handlers have already been initialized
 * @return void
 */
function roles_hooks_permissions() {
	$role = roles_get_role();
	if (!$role instanceof ElggRole) {
		return;
	}
	return roles()->setupHooks($role);
}

/**
 * Processes event permissions from the role configuration array. Triggered by the 'ready','system' event.
 * This is to make sure that all plugins' init functions have been executed, and all event handlers have already been initialized
 * @return void
 */
function roles_events_permissions() {
	$role = roles_get_role();
	if (!$role instanceof ElggRole) {
		return;
	}
	return roles()->setupEvents($role);
}

/**
 * Saves user role upon changing role on the user settings page
 *
 * @param string $hook_name    "usersettings:save"
 * @param string $entity_type  "user"
 * @param mixed  $return_value Return value
 * @param mixed  $params       An associative array of parameters provided by the hook trigger
 * @return void
 */
function roles_user_settings_save($hook_name, $entity_type, $return_value, $params) {
	$role_name = get_input('role');
	$user_id = get_input('guid');

	$role_name = roles_filter_role_name($role_name, $user_id);
	$role = roles_get_role_by_name($role_name);
	$user = get_entity($user_id);

	$res = roles_set_role($role, $user);

	if ($res === false) {
		register_error(elgg_echo('user:role:fail'));
		return false;
	} else if ($res === true) {
		system_message(elgg_echo('user:role:success'));
		return true;
	}
}

/**
 * Assigns user role when user is created
 *
 * @param string   $event "create"
 * @param string   $type  "user"
 * @param ElggUser $user  User entity
 * @return void
 */
function roles_create_user($event, $type, $user) {
	$rolename = get_input('role', false);
	if (elgg_is_admin_logged_in() && $rolename) {
		// admin is adding a user, give them the role they asked for
		$role = roles_get_role_by_name($rolename);

		if ($role) {
			roles_set_role($role, $user);
		}
	}
}
