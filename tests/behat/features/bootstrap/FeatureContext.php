<?php

use Drupal\DrupalExtension\Context\DrupalContext;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends DrupalContext implements SnippetAcceptingContext {

  /**
   * Initializes context.
   *
   * Every scenario gets its own context instance.
   * You can also pass arbitrary arguments to the
   * context constructor through behat.yml.
   */
  public function __construct() {
  }

  /**
   * @When I edit my profile
   */
  public function iEditMyProfile() {
    if (!$this->user->uid) {
      throw new \Exception('Cannot edit user profile when not logged in.');
    }
    $this->getSession()->visit('/user/' . $this->user->uid . '/edit');
  }

  /**
   * @Given a/an :role user named :username exists
   */
  public function userOfRoleExists($role_name, $username) {
    $role = user_role_load_by_name($role_name);
    $user = (object) array(
      'name' => $username,
      'pass' => user_password(),
      'email' =>  preg_replace('/\s+/', '_', $username) . '@lacunastories.com',
      'roles' => array(
        $role->rid => $role->name
      ),
    );
    $this->userCreate($user);
  }

  /**
   * @param $node_type
   * @param $node_title
   *
   * Find a node by its title. If duplicate node titles, will return the first.
   * @return bool|mixed
   */
  public function findNodeByTitle($node_type, $node_title) {
    $found_node = FALSE;
    // First check our test-generated nodes
    foreach ($this->nodes as $node) {
      if ($node->type == $node_type and $node->title == $node_title) {
        $found_node = $node;
        break;
      }
    }
    // Not found there. Query for existing nodes.
    if (!$found_node) {
      $query = new EntityFieldQuery();
      $result = $query->entityCondition('entity_type', 'node')
        ->propertyCondition('type', $node_type)
        ->propertyCondition('title', $node_title)
        ->propertyCondition('status', 1)
        ->range(0,1)
        ->execute();

      if (!empty($result['node'])) {
        $found_node = array_pop($result['node']);
      }
    }
    return $found_node;
  }

  /**
   * @When I go to the :type node named/titled :node_title
   */
  public function iGoToNodeTypeNamed($node_type, $node_title) {
    $node = $this->findNodeByTitle($node_type, $node_title);
    if (!$node) {
      throw new \Exception(sprintf('No %s node with title %s exists!', $node_type, $node_title));
    }
    $this->getSession()->visit('/node/' . $node->nid);
  }

  /**
   * @Given I am enrolled in the :course_title course
   */
  public function iAmEnrolledInCourse($course_title) {
    $group = $this->findNodeByTitle('course', $course_title);
    $membership = og_group('node', $group->nid, array(
      "entity type" => "user",
      "entity" => $this->user,
      "field_name" => "og_user_node",
    ));
    if (!$membership) {
      throw new \Exception(sprintf("User %s could not be enrolled in course %s", $this->user->name, $group->title));
    }
  }

  /**
   * @Given /^I am (?:a|an) "([^"]*)" of the current group$/
   */
  public function iAmAnOfTheCurrentGroup($group_role) {
    $group = $this->getCurrentGroupFromURL();
    $this->addMembertoGroup($group_role, $group);
  }

  /**
   * Adds a member to an organic group with the specified role.
   *
   * @param string $group_role
   *   The machine name of the group role.
   *
   * @param object $group
   *   The group node.
   *
   * @param string $group_type
   *   (optional) The group's entity type.
   *
   * @throws \Exception
   */
  public function addMembertoGroup($group_role, $group, $group_type = 'node') {
    $user = $this->getMainContext()->user;
    list($gid, $vid, $bundle) = entity_extract_ids($group_type, $group);

    $membership = og_group($group_type, $gid, array(
      "entity type" => 'user',
      "entity" => $user,
    ));

    if (!$membership) {
      throw new \Exception("The Organic Group membership could not be created.");
    }

    // Add role for membership.
    $roles = og_roles($group_type, $group->type, $gid);
    $rid = array_search($group_role, $roles);

    if (!$rid) {
      throw new \Exception("'$group_role' is not a valid group role.");
    }

    og_role_grant($group_type, $gid, $user->uid, $rid);

  }

  /**
   * Gets current Organic Group context from URL.
   */
  public function getCurrentGroupFromURL() {
    // Get group context using node from current URL. Note that we cannot rely
    // on menu_get_item(), or og_context_determine_context() due to $_GET['q']
    // not being set correctly.
    $group_type = 'node';
    $node = $this->getMainContext()->getNodeFromUrl();
    $context = _group_context_handler_entity($group_type, $node);
    if (!$context) {
      throw new \Exception("No active organic group context could be found.");
    }
    $gid = reset($context[$group_type]);
    $group = entity_load_single($group_type, $gid);

    return $group;
  }

  /**
   * @Given /^I visit the parent group node$/
   */
  public function iVisitTheParentGroupNode() {
    $group = $this->getCurrentGroupFromURL();
    $this->getSession()->visit($this->getMainContext()
      ->locatePath('/node/' . $group->nid));
  }

  /**
   * @Given /^I create a "([^"]*)" node belonging to the current organic group$/
   */
  public function iCreateANodeBelongingToTheCurrentOrganicGroup($content_entity_type) {
    // Get the current group.
    $group = $this->getCurrentGroupFromURL();

    // Create the group content.
    $properties = array('og_group_ref' => $group->nid);
    // Creating the node will automatically redirect the browser to view it.
    $this->getMainContext()->createNode($content_entity_type, $properties);
  }

  /**
   * @Given /^I am logged in as a user in the "(?P<group>[^"]*)" group$/
   */
  public function iAmLoggedInAsAUserInTheGroup($group) {
    // Create user.
    $account = (object) array(
      'pass' => $this->getDrupal()->random->name(),
      'name' => $this->getDrupal()->random->name(),
    );
    $account->mail = "{$account->name}@example.com";
    // Set the group.
    $account->og_user_node[LANGUAGE_NONE][0]['target_id'] = $this->getGroupNidFromTitle($group);
    $account->og_user_node[LANGUAGE_NONE][0]['state'] = OG_STATE_ACTIVE;
    $this->getDriver()->userCreate($account);
    $this->users[$account->name] = $this->user = $account;
    // Log in.
    $this->login();
  }

  /**
   * @Given /^I am logged in as an "(?P<role>[^"]*)" in the "(?P<group>[^"]*)" state group$/
   */
  public function iAmLoggedInAsATypeUserInStateGroup($role, $group) {
    // Create user.
    $account = (object) array(
      'pass' => $this->getDrupal()->random->name(),
      'name' => $this->getDrupal()->random->name(),
    );
    $account->mail = "{$account->name}@example.com";
    // Set the group.
    $group_id = $this->getGroupNidFromTitle($group, 'state_office');
    $account->og_user_node1[LANGUAGE_NONE][0]['target_id'] = $group_id;
    $account->og_user_node1[LANGUAGE_NONE][0]['state'] = OG_STATE_ACTIVE;
    // User should be in the state offices group as well.
    $state_office_id = $this->getGroupNidFromTitle('State Offices');
    $account->og_user_node[LANGUAGE_NONE][1]['target_id'] = $state_office_id;
    $account->og_user_node[LANGUAGE_NONE][1]['state'] = OG_STATE_ACTIVE;
    $this->getDriver()->userCreate($account);
    $this->users[$account->name] = $this->user = $account;
    // Set user as group admin.
    $roles = array_flip(og_roles('node', 'state_office', $group_id));
    if (!isset($roles[$role])) {
      throw new \Exception(sprintf('No group role with the name %s exists!', $role));
    }
    og_role_grant('node', $group_id, $account->uid, $roles[$role]);
    // If user is an admin, they should get the site role 'state office editor'.
    if ($role == 'administrator member') {
      $this->getDriver()->userAddRole($account, 'state office editor');
    }
    // Log in.
    $this->login();
  }

  /**
   * Given a group title, find the nid.
   *
   * @param string $group
   *   The group title to search for.
   * @param string $type
   *   The node type to use. Defaults to `site_group`.
   *
   * @return integer
   *   The node ID of the group.
   */
  protected function getGroupNidFromTitle($group, $type = 'site_group') {
    // Find group node.
    $group_nid = db_query("SELECT nid FROM {node} WHERE type = :type AND title = :title", array(
      ':type' => $type,
      ':title' => $group
    ))->fetchField();
    if (!$group_nid) {
      throw new \Exception(sprintf('No group "%s" found!', $group));
    }
    return $group_nid;
  }
}