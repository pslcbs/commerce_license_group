<?php

namespace Drupal\commerce_license_group\Plugin\Commerce\LicenseType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
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
    /** Array of @var \Drupal\group\Entity\GroupInterface $groups */
    $groups = $license->license_group->referencedEntities();
    $group_labels = [];
    foreach ($groups as $group) {
      $group_labels[]= $group->label();
    }
    $group_labels = implode( ', ', $group_labels);
    $args = [
      '@group_labels' => $group_labels,
    ];
    return $this->t('Group/s membership licensed: @group_labels', $args);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'license_group' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function grantLicense(LicenseInterface $license) {
    /** Array of @var \Drupal\group\Entity\GroupInterface $groups */
    $groups = $license->license_group->referencedEntities();
    
    // Get the owner of the license and grant them group membership.
    $owner = $license->getOwner();
    
    foreach ($groups as $group){
      if ($group instanceof \Drupal\group\Entity\GroupInterface) {
        $group->addMember($owner);
      }
      else {
        \Drupal::logger('commerce_license_group')->error("Couldn't get group for license " . $license->id());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function revokeLicense(LicenseInterface $license) {
    
    /** Array of @var \Drupal\group\Entity\Group $groups */
    $groups = $license->license_group->referencedEntities();
    
    // Get the owner of the license and remove their group membership.
    $owner = $license->getOwner();
    
    foreach ($groups as $group){
      $group->removeMember($owner);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkUserHasExistingRights(UserInterface $user) {
    $group_ids = $this->configuration['license_group'];

    /** Array of @var \Drupal\group\Entity\Group $group */
    $groups = \Drupal::service('entity_type.manager')->getStorage('group')->loadMultiple($group_ids);
    
    if (empty($groups)) {
      return ExistingRightsResult::rightsDoNotExist();
    }
    
    foreach ($groups as $id => $group){
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
  }

  /**
   * {@inheritdoc}
   */
  public function alterEntityOwnerForm(&$form, FormStateInterface $form_state, $form_id, LicenseInterface $license, EntityInterface $form_entity) {

    if (preg_match('/^group_content_(.+)-group_membership_delete_form$/', $form_id)) {
      
      /** Array of @var \Drupal\group\Entity\Group $group */
      $groups = $license->license_group->referencedEntities();
      
      foreach ($groups as $group) {
        /** @var \Drupal\group\Entity\GroupContent $form_entity */
        if ($form_entity->getGroup()->id() == $group->id()) {
          \Drupal::messenger()->addWarning("This group membership is granted by a license. It should not be removed manually.");
        }
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
      $options[$group->getGroupType()->label()][$id] = $group->label();
    }

    $form['license_group'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#size' => 15,
      '#title' => $this->t('Select the group/s membership that will be purchased'),
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
      ->setLabel(t('Group/s'))
      ->setDescription(t('The group/s this product grants membership of.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
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
