<?php

namespace Drupal\commerce_license_group\Plugin\Commerce\LicenseType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\entity\BundleFieldDefinition;
use Drupal\commerce_license\Entity\LicenseInterface;
use Drupal\commerce_license\ExistingRights\ExistingRightsResult;
use Drupal\commerce_license\Plugin\Commerce\LicenseType\LicenseTypeBase;
use Drupal\commerce_license\Plugin\Commerce\LicenseType\ExistingRightsFromConfigurationCheckingInterface;
use Drupal\commerce_license\Plugin\Commerce\LicenseType\GrantedEntityLockingInterface;
use Drupal\group\GroupMembership as GroupMembershipEntity;

/**
 * Provides a license type which grants membership of a group.
 *
 * @CommerceLicenseType(
 *   id = "group_membership",
 *   label = @Translation("Group membership"),
 * )
 */
class GroupMembership extends LicenseTypeBase implements ExistingRightsFromConfigurationCheckingInterface, GrantedEntityLockingInterface {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(LicenseInterface $license) {
    $args = [
      '@group' => $license->license_group->entity->label(),
    ];
    return $this->t('@group group membership license', $args);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'license_group' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function grantLicense(LicenseInterface $license) {

    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = $license->license_group->entity;

    if ($group instanceof \Drupal\group\Entity\GroupInterface) {

      // Get the owner of the license and grant them group membership.
      $owner = $license->getOwner();

      $group->addMember($owner);
    }
    else {
      \Drupal::logger('commerce_license_group')->error("Couldn't get group for license " . $license->id());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function revokeLicense(LicenseInterface $license) {

    /** @var \Drupal\group\Entity\Group $group */
    $group = $license->license_group->entity;

    // Get the owner of the license and remove their group membership.
    $owner = $license->getOwner();

    $group->removeMember($owner);
  }

  /**
   * {@inheritdoc}
   */
  public function checkUserHasExistingRights(UserInterface $user) {
    $group_id = $this->configuration['license_group'];

    /** @var \Drupal\group\Entity\Group $group */
    $group = \Drupal::service('entity_type.manager')->getStorage('group')->load($group_id);

    if (empty($group)) {
      return ExistingRightsResult::rightsDoNotExist();
    }

    $userIsMember = $group->getMember($user) instanceof GroupMembershipEntity;

    return ExistingRightsResult::rightsExistIf(
      $userIsMember,
      $this->t("You are already a member of the @group-label group.", [
        '@group-label' => $group->label(),
      ]),
      $this->t("User @user is already a member of the @group-label group.", [
        '@user' => $user->getDisplayName(),
        '@group-label' => $group->label(),
      ])
    );
  }

  /**
   * {@inheritdoc}
   */
  public function alterEntityOwnerForm(&$form, FormStateInterface $form_state, $form_id, LicenseInterface $license, EntityInterface $form_entity) {

    if (preg_match('/^group_content_(.+)-group_membership_delete_form$/', $form_id)) {

      /** @var \Drupal\group\Entity\GroupContent $form_entity */
      if ($form_entity->getGroup()->id() == $license->license_group->entity->id()) {
        \Drupal::messenger()->addWarning("This group membership is granted by a license. It should not be removed manually.");
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $groups = \Drupal::service('entity_type.manager')->getStorage('group')->loadMultiple();

    $options = [];
    foreach ($groups as $id => $group) {
      $options[$id] = $group->label();
    }

    $form['license_group'] = [
      '#type' => 'radios',
      '#title' => $this->t('Group membership to purchase'),
      '#options' => $options,
      '#default_value' => $this->configuration['license_group'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue($form['#parents']);

    $this->configuration['license_group'] = $values['license_group'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();

    $fields['license_group'] = BundleFieldDefinition::create('entity_reference')
      ->setLabel(t('Group'))
      ->setDescription(t('The group this product grants membership of.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setSetting('target_type', 'group')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 1,
        'settings' => [
          'link' => TRUE,
        ],
      ]);

    return $fields;
  }

}
