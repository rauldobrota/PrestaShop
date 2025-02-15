# ./vendor/bin/behat -c tests/Integration/Behaviour/behat.yml -s module --tags module
@restore-all-tables-before-feature
@clear-cache-before-feature
@reset-test-modules-after-feature
@module
Feature: Module
  PrestaShop allows BO users to manage modules
  As a BO user
  I must be able to enable/disable modules

  Scenario: Bulk Status
    Given module ps_featuredproducts has following infos:
      | technical_name | ps_featuredproducts |
      | version        | 1.0.0               |
      | enabled        | true                |
      | installed      | true                |
    Given module ps_emailsubscription has following infos:
      | technical_name | ps_emailsubscription |
      | version        | 1.0.0                |
      | enabled        | true                 |
      | installed      | true                 |
    When I bulk disable modules: "ps_featuredproducts,ps_emailsubscription"
    Then module ps_featuredproducts has following infos:
      | enabled | false |
    And module ps_emailsubscription has following infos:
      | enabled | false |
    When I bulk enable modules: "ps_emailsubscription"
    Then module ps_featuredproducts has following infos:
      | enabled | false |
    And module ps_emailsubscription has following infos:
      | enabled | true |
    When I bulk enable modules: "ps_featuredproducts"
    Then module ps_featuredproducts has following infos:
      | enabled | true |
    And module ps_emailsubscription has following infos:
      | enabled | true |

  Scenario: Update module status
    Given module ps_featuredproducts has following infos:
      | enabled | true |
    When I disable module "ps_featuredproducts"
    Then module ps_featuredproducts has following infos:
      | enabled | false |
    When I enable module "ps_featuredproducts"
    Given module ps_featuredproducts has following infos:
      | enabled | true |

  Scenario: Reset module status
    Given module ps_featuredproducts has following infos:
      | technical_name | ps_featuredproducts |
      | enabled        | true |
      | installed      | true |
    When I disable module "ps_featuredproducts"
    And I reset module "ps_featuredproducts"
    Then I should have an exception that disabled module cannot be reset
    When I enable module "ps_featuredproducts"
    When I reset module "ps_featuredproducts"
    Then module ps_featuredproducts has following infos:
      | technical_name | ps_featuredproducts |
      | enabled        | true |
      | installed      | true |

  Scenario: Get module infos
    Then module ps_emailsubscription has following infos:
      | technical_name | ps_emailsubscription |
      | version        | 1.0.0                |
      | enabled        | true                 |
      | installed      | true                 |

  Scenario: Uninstall modules
    Given module ps_featuredproducts has following infos:
      | technical_name | ps_featuredproducts |
      | version        | 1.0.0               |
      | enabled        | true                |
      | installed      | true                |
    Given module ps_emailsubscription has following infos:
      | technical_name | ps_emailsubscription |
      | version        | 1.0.0                |
      | enabled        | true                 |
      | installed      | true                 |
    When I bulk uninstall modules: "ps_featuredproducts,ps_emailsubscription" with deleteFile false
    Then module ps_featuredproducts has following infos:
      | installed      | false               |
    And module ps_emailsubscription has following infos:
      | installed      | false               |

  Scenario: Uninstall module
    Given module bankwire has following infos:
      | technical_name | bankwire            |
      | version        | 2.0.0               |
      | enabled        | true                |
      | installed      | true                |
    When I uninstall module "bankwire" with deleteFile true
    And module bankwire has following infos:
      | installed      | false               |
    Then I should have an exception that module is not found

  Scenario: Uninstall module are not installed
    Given module ps_featuredproducts has following infos:
      | technical_name | ps_featuredproducts |
      | version        | 1.0.0               |
      | enabled        | false                |
      | installed      | false               |
    When I uninstall module "ps_featuredproducts" with deleteFile false
    Then I should have an exception that module is not installed

  Scenario: Get module not present
    When module ps_notthere has following infos:
      | technical_name | ps_notthere |
      | version        | 1.0.0       |
      | enabled        | true        |
      | installed      | true        |
    Then I should have an exception that module is not found