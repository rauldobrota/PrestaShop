/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

import ProductMap from '@pages/product/product-map';
import CombinationsGridRenderer from '@pages/product/edit/combinations-grid-renderer';
import CombinationsService from '@pages/product/services/combinations-service';
import DynamicPaginator from '@components/pagination/dynamic-paginator';
import ProductEventMap from '@pages/product/product-event-map';
import initCombinationModal from '@pages/product/components/combination-modal';
import initFilters from '@pages/product/components/filters';
import ConfirmModal from '@components/modal';
import {EventEmitter} from 'events';
import initCombinationGenerator from '@pages/product/components/generator';
import {getProductAttributeGroups} from '@pages/product/services/attribute-groups';
import BulkFormHandler from '@pages/product/combination/bulk-form-handler';
import PaginatedCombinationsService from '@pages/product/services/paginated-combinations-service';
import BulkDeleteHandler from '@pages/product/combination/bulk-delete-handler';
import BulkChoicesSelector from '@pages/product/combination/bulk-choices-selector';
import ProductFormModel from '@pages/product/edit/product-form-model';
import CombinationsListEditor from '@pages/product/edit/combinations-list-editor';

const {$} = window;
const CombinationEvents = ProductEventMap.combinations;
const CombinationsMap = ProductMap.combinations;

export default class CombinationsManager {
  productId: number;

  eventEmitter: EventEmitter;

  externalCombinationTab: HTMLDivElement;

  $productForm: JQuery;

  $combinationsFormContainer: JQuery;

  $preloader: JQuery;

  $paginatedList: JQuery;

  $emptyState: JQuery;

  paginator?: DynamicPaginator;

  combinationsRenderer?: CombinationsGridRenderer;

  filtersApp?: Record<string, any>;

  combinationModalApp: Record<string, any> | null;

  combinationGeneratorApp!: Record<string, any>;

  initialized: boolean;

  combinationsService: CombinationsService;

  paginatedCombinationsService: PaginatedCombinationsService;

  productAttributeGroups: Array<Record<string, any>>;

  productFormModel: ProductFormModel;

  combinationsEditor?: CombinationsListEditor;

  constructor(productId: number, productFormModel: ProductFormModel) {
    this.productId = productId;
    this.productFormModel = productFormModel;
    this.eventEmitter = window.prestashop.instance.eventEmitter;
    this.$productForm = $(ProductMap.productForm);
    this.$combinationsFormContainer = $(CombinationsMap.combinationsFormContainer);
    this.externalCombinationTab = document.querySelector<HTMLDivElement>(CombinationsMap.externalCombinationTab)!;

    this.$preloader = $(CombinationsMap.preloader);
    this.$paginatedList = $(CombinationsMap.combinationsPaginatedList);
    this.$emptyState = $(CombinationsMap.emptyState);

    this.combinationModalApp = null;

    this.initialized = false;
    this.combinationsService = new CombinationsService();
    this.paginatedCombinationsService = new PaginatedCombinationsService(productId);
    this.productAttributeGroups = [];

    const bulkChoicesSelector = new BulkChoicesSelector(this.externalCombinationTab);
    new BulkFormHandler(productId, bulkChoicesSelector);
    new BulkDeleteHandler(productId, bulkChoicesSelector);

    this.init();
  }

  private init(): void {
    // Paginate to first page when tab is shown
    this.$productForm
      .find(CombinationsMap.navigationTab)
      .on('shown.bs.tab', () => this.showCombinationTab());
    this.$productForm
      .find(CombinationsMap.navigationTab)
      .on('hidden.bs.tab', () => this.hideCombinationTab());

    // Finally watch events related to combination listing
    this.watchEvents();
  }

  /**
   * @private
   */
  private showCombinationTab(): void {
    this.externalCombinationTab.classList.remove('d-none');
    this.firstInit();
  }

  /**
   * @private
   */
  private hideCombinationTab(): void {
    this.externalCombinationTab.classList.add('d-none');
  }

  /**
   * @private
   */
  private firstInit(): void {
    if (this.initialized) {
      return;
    }

    this.initialized = true;

    this.combinationGeneratorApp = initCombinationGenerator(
      CombinationsMap.combinationsGeneratorContainer,
      this.eventEmitter,
      this.productId,
    );
    this.combinationModalApp = initCombinationModal(
      CombinationsMap.editModal,
      this.productId,
      this.eventEmitter,
    );
    this.filtersApp = initFilters(
      CombinationsMap.combinationsFiltersContainer,
      this.eventEmitter,
      this.productAttributeGroups,
    );
    this.initPaginatedList();

    this.refreshCombinationList(true);
  }

  /**
   * @param {boolean} firstTime
   * @returns {Promise<void>}
   *
   * @private
   */
  private async refreshCombinationList(firstTime: boolean): Promise<void> {
    // Preloader is only shown on first load
    this.$preloader.toggleClass('d-none', !firstTime);
    this.$paginatedList.toggleClass('d-none', firstTime);
    this.$emptyState.addClass('d-none');

    // Wait for product attributes to adapt rendering depending on their number
    this.productAttributeGroups = await getProductAttributeGroups(this.productId);

    if (this.filtersApp) {
      this.filtersApp.filters = this.productAttributeGroups;
    }

    // We trigger the clearFilters which will be handled by the filters app, after clean the component will trigger
    // the updateAttributeGroups event which is caught by this manager which will in turn refresh the list to first page
    this.eventEmitter.emit(CombinationEvents.clearFilters);
    this.$preloader.addClass('d-none');

    const hasCombinations = this.productAttributeGroups && this.productAttributeGroups.length;
    this.$paginatedList.toggleClass('d-none', !hasCombinations);

    if (!hasCombinations && this.combinationsRenderer) {
      // Empty list
      this.combinationsRenderer.render({combinations: []});
      this.$emptyState.removeClass('d-none');
    }
  }

  /**
   * @private
   */
  private refreshPage(): void {
    if (this.paginator) {
      this.paginator.paginate(this.paginator.getCurrentPage());
    }
  }

  /**
   * @private
   */
  private initPaginatedList(): void {
    this.combinationsRenderer = new CombinationsGridRenderer(
      this.eventEmitter,
      this.productFormModel,
      (sortColumn: string, sorOrder: string) => this.sortList(sortColumn, sorOrder),
    );

    this.paginator = new DynamicPaginator(
      CombinationsMap.paginationContainer,
      this.paginatedCombinationsService,
      this.combinationsRenderer,
      0,
    );

    this.$combinationsFormContainer.on(
      'click',
      CombinationsMap.deleteCombinationSelector,
      async (e) => {
        await this.deleteCombination(e.currentTarget);
      },
    );

    this.combinationsEditor = new CombinationsListEditor(
      this.productId,
      this.eventEmitter,
      this.combinationsRenderer,
    );
  }

  private sortList(sortColumn: string, sortOrder: string): void {
    if (this.combinationsEditor?.editionEnabled) {
      return;
    }

    this.paginatedCombinationsService.setOrderBy(sortColumn, sortOrder);
    if (this.paginator) {
      this.paginator.paginate(1);
    }
  }

  private watchEvents(): void {
    this.eventEmitter.on(CombinationEvents.refreshCombinationList, () => this.refreshCombinationList(false));
    this.eventEmitter.on(CombinationEvents.refreshPage, () => this.refreshPage());
    /* eslint-disable */
    this.eventEmitter.on(
      CombinationEvents.updateAttributeGroups,
      attributeGroups => {
        const currentFilters = this.paginatedCombinationsService.getFilters();
        currentFilters.attributes = {};
        Object.keys(attributeGroups).forEach(attributeGroupId => {
          currentFilters.attributes[attributeGroupId] = [];
          const attributes = attributeGroups[attributeGroupId];
          attributes.forEach((attribute: Record<string, any>) => {
            currentFilters.attributes[attributeGroupId].push(attribute.id);
          });
        });

        this.paginatedCombinationsService.setFilters(currentFilters);
        if(this.paginator) {
          this.paginator.paginate(1);
        }
      }
    );

    this.eventEmitter.on(CombinationEvents.combinationGeneratorReady, () => {
      const $generateButtons = $(
        CombinationsMap.generateCombinationsButton
      );
      $generateButtons.prop('disabled', false);
      $('body').on(
        'click',
        CombinationsMap.generateCombinationsButton,
        event => {
          // Stop event or it will be caught by click-outside directive and automatically close the modal
          event.stopImmediatePropagation();
          this.eventEmitter.emit(CombinationEvents.openCombinationsGenerator);
        }
      );
    });

    this.eventEmitter.on(CombinationEvents.bulkUpdateFinished, () => this.refreshPage());
  }

  /**
   * @param {HTMLElement} button
   *
   * @private
   */
  private async deleteCombination(button: HTMLButtonElement): Promise<void> {
    try {
      const $deleteButton = $(button);
      const modal = new (ConfirmModal as any)(
        {
          id: 'modal-confirm-delete-combination',
          confirmTitle: $deleteButton.data('modal-title'),
          confirmMessage: $deleteButton.data('modal-message'),
          confirmButtonLabel: $deleteButton.data('modal-apply'),
          closeButtonLabel: $deleteButton.data('modal-cancel'),
          confirmButtonClass: 'btn-danger',
          closable: true,
        },
        async () => {
          const response = await this.combinationsService.deleteCombination(
            this.findCombinationId(button),
          );
          $.growl({message: response.message});
          this.eventEmitter.emit(CombinationEvents.refreshCombinationList);
        },
      );
      modal.show();
    } catch (error) {
      const errorMessage = error.responseJSON
        ? error.responseJSON.error
        : error;
      $.growl.error({message: errorMessage});
    }
  }

  /**
   * @param {HTMLElement} input of the same table row
   *
   * @returns {Number}
   *
   * @private
   */
  private findCombinationId(input: HTMLElement): number {
    return Number($(input)
      .closest('tr')
      .find(CombinationsMap.combinationIdInputsSelector)
      .val());
  }
}
