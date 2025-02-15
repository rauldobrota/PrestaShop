<?php
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

namespace PrestaShopBundle\Controller\Admin\Sell\Catalog;

use Category;
use Dispatcher;
use Exception;
use ImageManager;
use PrestaShop\PrestaShop\Adapter\Category\CategoryDataProvider;
use PrestaShop\PrestaShop\Adapter\Category\CategoryViewDataProvider;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\BulkDeleteCategoriesCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\BulkDisableCategoriesCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\BulkEnableCategoriesCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\DeleteCategoryCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\DeleteCategoryCoverImageCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\DeleteCategoryThumbnailImageCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\SetCategoryIsEnabledCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Command\UpdateCategoryPositionCommand;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CannotAddCategoryException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CannotDeleteImageException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CannotDeleteRootCategoryForShopException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CannotEditCategoryException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CannotEditRootCategoryException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CannotUpdateCategoryStatusException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CategoryConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CategoryException;
use PrestaShop\PrestaShop\Core\Domain\Category\Exception\CategoryNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Category\Query\GetCategoriesTree;
use PrestaShop\PrestaShop\Core\Domain\Category\Query\GetCategoryForEditing;
use PrestaShop\PrestaShop\Core\Domain\Category\Query\GetCategoryIsEnabled;
use PrestaShop\PrestaShop\Core\Domain\Category\QueryResult\CategoryForTree;
use PrestaShop\PrestaShop\Core\Domain\Category\QueryResult\EditableCategory;
use PrestaShop\PrestaShop\Core\Domain\ShowcaseCard\Query\GetShowcaseCardIsClosed;
use PrestaShop\PrestaShop\Core\Domain\ShowcaseCard\ValueObject\ShowcaseCard;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\CategoryGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Group\Provider\DefaultGroupsProviderInterface;
use PrestaShop\PrestaShop\Core\Image\Uploader\Exception\UploadedImageConstraintException;
use PrestaShop\PrestaShop\Core\Kpi\Row\KpiRowFactoryInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\CategoryFilters;
use PrestaShopBundle\Component\CsvResponse;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Form\Admin\Sell\Category\DeleteCategoriesType;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class CategoryController is responsible for "Sell > Catalog > Categories" page.
 */
class CategoryController extends PrestaShopAdminController
{
    /**
     * Show categories listing.
     *
     * @param Request $request
     * @param CategoryFilters $filters
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('create', request.get('_legacy_controller')) || is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to list this.')]
    public function indexAction(
        Request $request,
        CategoryFilters $filters,
        #[Autowire(service: 'prestashop.core.kpi_row.factory.categories')]
        KpiRowFactoryInterface $categoriesKpiFactory,
        #[Autowire(service: 'prestashop.adapter.category.category_view_data_provider')]
        CategoryViewDataProvider $categoryViewDataProvider,
        #[Autowire(service: 'prestashop.core.grid.factory.category_decorator')]
        GridFactoryInterface $categoryGridFactory,
    ): Response {
        $currentCategoryId = (int) $filters->getFilters()['id_category_parent'];
        $categoryViewData = $categoryViewDataProvider->getViewData($currentCategoryId);

        $isItASearchRequest = $this->requestHasSearchParameters($request);

        $filters->addFilter(['is_home_category' => $categoryViewData['is_home_category']]);
        $filters->addFilter(['is_search_request' => $isItASearchRequest]);

        $categoryGrid = $categoryGridFactory->getGrid($filters);

        $deleteCategoriesForm = $this->createForm(DeleteCategoriesType::class, ['categories_to_delete_parent' => $currentCategoryId], []);

        $showcaseCardIsClosed = $this->dispatchQuery(
            new GetShowcaseCardIsClosed($this->getEmployeeContext()->getEmployee()->getId(), ShowcaseCard::CATEGORIES_CARD)
        );

        $layoutTitle = $this->trans('Categories', [], 'Admin.Navigation.Menu');
        if (!$categoryViewData['is_home_category']) {
            $layoutTitle = $this->trans('Category %name%', ['%name%' => $categoryViewData['name']], 'Admin.Navigation.Menu');
        }

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Categories/index.html.twig', [
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
            'enableSidebar' => true,
            'categoriesGrid' => $this->presentGrid($categoryGrid),
            'categoriesKpi' => $categoriesKpiFactory->build(),
            'layoutHeaderToolbarBtn' => $this->getCategoryIndexToolbarButtons($request, $currentCategoryId),
            'currentCategoryView' => $categoryViewData,
            'deleteCategoriesForm' => $deleteCategoriesForm->createView(),
            'isSingleShopContext' => $this->getShopContext()->getShopConstraint()->isSingleShopContext(),
            'showcaseCardName' => ShowcaseCard::CATEGORIES_CARD,
            'isShowcaseCardClosed' => $showcaseCardIsClosed,
            'layoutTitle' => $layoutTitle,
        ]);
    }

    /**
     * Show "Add new" form and handle form submit.
     *
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.', redirectRoute: 'admin_categories_index')]
    public function createAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.category_form_builder')]
        FormBuilderInterface $categoryFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.handler.category_form_handler')]
        FormHandlerInterface $categoryFormHandler,
        #[Autowire(service: 'prestashop.adapter.group.provider.default_groups_provider')]
        DefaultGroupsProviderInterface $defaultGroupsProvider
    ): Response {
        $configuration = $this->getConfiguration();
        $parentId = (int) $request->query->get('id_parent', (int) $configuration->get('PS_HOME_CATEGORY'));

        /*
         * Parent category can be root category only if you specifically click to add root category.
         * In all other cases it should be at least home category(Or one of it's children).
         */
        $configRootCategory = (int) $configuration->get('PS_ROOT_CATEGORY');
        if ($parentId === $configRootCategory) {
            $parentId = (int) $configuration->get('PS_HOME_CATEGORY');
        }

        $categoryForm = $categoryFormBuilder->getForm(['id_parent' => $parentId]);
        $categoryForm->handleRequest($request);

        try {
            $handlerResult = $categoryFormHandler->handle($categoryForm);

            if (null !== $handlerResult->getIdentifiableObjectId()) {
                $this->addFlash('success', $this->trans('Successful creation', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_categories_index', [
                    'categoryId' => $categoryForm->getData()['id_parent'],
                ]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        $defaultGroups = $defaultGroupsProvider->getGroups();

        return $this->render(
            '@PrestaShop/Admin/Sell/Catalog/Categories/create.html.twig',
            [
                'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
                'enableSidebar' => true,
                'categoryForm' => $categoryForm->createView(),
                'defaultGroups' => $defaultGroups,
                'layoutTitle' => $this->trans('New category', [], 'Admin.Navigation.Menu'),
                'categoryUrl' => null,
            ]
        );
    }

    /**
     * Show "Add new root category" page & process adding.
     *
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.', redirectRoute: 'admin_categories_index')]
    public function createRootAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.root_category_form_builder')]
        FormBuilderInterface $rootCategoryFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.handler.root_category_form_handler')]
        FormHandlerInterface $rootCategoryFormHandler,
        #[Autowire(service: 'prestashop.adapter.group.provider.default_groups_provider')]
        DefaultGroupsProviderInterface $defaultGroupsProvider
    ): Response {
        $rootCategoryForm = $rootCategoryFormBuilder->getForm();
        $rootCategoryForm->handleRequest($request);

        try {
            $handlerResult = $rootCategoryFormHandler->handle($rootCategoryForm);

            if (null !== $handlerResult->getIdentifiableObjectId()) {
                $this->addFlash('success', $this->trans('Successful creation', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_categories_index', [
                    'categoryId' => (int) $this->getConfiguration()->get('PS_ROOT_CATEGORY'),
                ]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        $defaultGroups = $defaultGroupsProvider->getGroups();

        return $this->render(
            '@PrestaShop/Admin/Sell/Catalog/Categories/create_root.html.twig',
            [
                'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
                'enableSidebar' => true,
                'rootCategoryForm' => $rootCategoryForm->createView(),
                'defaultGroups' => $defaultGroups,
                'layoutTitle' => $this->trans('New category', [], 'Admin.Navigation.Menu'),
            ]
        );
    }

    /**
     * Show & process category editing.
     *
     * @param int $categoryId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to edit this.', redirectRoute: 'admin_categories_index')]
    public function editAction(
        int $categoryId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.category_form_builder')]
        FormBuilderInterface $categoryFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.handler.category_form_handler')]
        FormHandlerInterface $categoryFormHandler,
        #[Autowire(service: 'prestashop.adapter.group.provider.default_groups_provider')]
        DefaultGroupsProviderInterface $defaultGroupsProvider,
    ): Response {
        try {
            /** @var EditableCategory $editableCategory */
            $editableCategory = $this->dispatchQuery(new GetCategoryForEditing((int) $categoryId));

            if ($editableCategory->isRootCategory()) {
                return $this->redirectToRoute('admin_categories_edit_root', ['categoryId' => $categoryId]);
            }
        } catch (CategoryException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_categories_index');
        }

        $categoryFormOptions = [
            'id_category' => (int) $categoryId,
            'subcategories' => $editableCategory->getSubCategories(),
        ];

        try {
            $categoryForm = $categoryFormBuilder->getFormFor((int) $categoryId, [], $categoryFormOptions);
        } catch (Exception $exception) {
            $this->addFlash('error', $this->getErrorMessageForException($exception, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_categories_index');
        }

        try {
            $categoryForm->handleRequest($request);
            $handlerResult = $categoryFormHandler->handleFor((int) $categoryId, $categoryForm);

            if ($handlerResult->isSubmitted() && $handlerResult->isValid()) {
                $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_categories_index', [
                    'categoryId' => $categoryForm->getData()['id_parent'],
                ]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        $defaultGroups = $defaultGroupsProvider->getGroups();

        // If we don't create the dispatcher instance with the current request,
        // a new instance will be created later using `SymfonyRequest::createFromGlobals()`
        // but as we may have already uploaded files, this can throw an exception
        Dispatcher::getInstance($request);

        return $this->render(
            '@PrestaShop/Admin/Sell/Catalog/Categories/edit.html.twig',
            [
                'categoryId' => $categoryId,
                'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
                'enableSidebar' => true,
                'contextLangId' => $this->getLanguageContext()->getId(),
                'editCategoryForm' => $categoryForm->createView(),
                'editableCategory' => $editableCategory,
                'defaultGroups' => $defaultGroups,
                'layoutTitle' => $this->trans(
                    'Editing category %category_name%',
                    [
                        '%category_name%' => $editableCategory->getName()[$this->getLanguageContext()->getId()],
                    ],
                    'Admin.Navigation.Menu'
                ),
            ]
        );
    }

    /**
     * Show and process category editing.
     *
     * @param int $categoryId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to edit this.', redirectRoute: 'admin_categories_index')]
    public function editRootAction(
        int $categoryId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.root_category_form_builder')]
        FormBuilderInterface $rootCategoryFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.handler.root_category_form_handler')]
        FormHandlerInterface $rootCategoryFormHandler,
        #[Autowire(service: 'prestashop.adapter.group.provider.default_groups_provider')]
        DefaultGroupsProviderInterface $defaultGroupsProvider
    ): Response {
        try {
            /** @var EditableCategory $editableCategory */
            $editableCategory = $this->dispatchQuery(new GetCategoryForEditing((int) $categoryId));

            if (!$editableCategory->isRootCategory()) {
                return $this->redirectToRoute('admin_categories_edit', ['categoryId' => $categoryId]);
            }
        } catch (CannotEditRootCategoryException|CategoryNotFoundException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_categories_index');
        }

        try {
            $rootCategoryForm = $rootCategoryFormBuilder->getFormFor((int) $categoryId);
        } catch (Exception $exception) {
            $this->addFlash('error', $this->getErrorMessageForException($exception, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_categories_index');
        }

        try {
            $rootCategoryForm->handleRequest($request);
            $handlerResult = $rootCategoryFormHandler->handleFor((int) $categoryId, $rootCategoryForm);

            if ($handlerResult->isSubmitted() && $handlerResult->isValid()) {
                $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_categories_index', [
                    'categoryId' => (int) $this->getConfiguration()->get('PS_ROOT_CATEGORY'),
                ]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        $defaultGroups = $defaultGroupsProvider->getGroups();

        return $this->render(
            '@PrestaShop/Admin/Sell/Catalog/Categories/edit_root.html.twig',
            [
                'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
                'enableSidebar' => true,
                'categoryId' => $categoryId,
                'contextLangId' => $this->getLanguageContext()->getId(),
                'editRootCategoryForm' => $rootCategoryForm->createView(),
                'editableCategory' => $editableCategory,
                'defaultGroups' => $defaultGroups,
                'layoutTitle' => $this->trans(
                    'Editing category %category_name%',
                    [
                        '%category_name%' => $editableCategory->getName()[$this->getLanguageContext()->getId()],
                    ],
                    'Admin.Navigation.Menu'
                ),
            ]
        );
    }

    /**
     * Deletes category cover image.
     *
     * @param Request $request
     * @param int $categoryId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to edit this.', redirectRoute: 'admin_categories_edit', redirectQueryParamsToKeep: ['categoryId'])]
    public function deleteCoverImageAction(Request $request, $categoryId)
    {
        try {
            $this->dispatchCommand(new DeleteCategoryCoverImageCommand((int) $categoryId));

            $this->addFlash(
                'success',
                $this->trans('Image successfully deleted.', [], 'Admin.Notifications.Success')
            );
        } catch (CategoryException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_categories_edit', [
            'categoryId' => $categoryId,
        ]);
    }

    /**
     * Deletes category thumbnail image.
     *
     * @param Request $request
     * @param int $categoryId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to edit this.', redirectRoute: 'admin_categories_edit', redirectQueryParamsToKeep: ['categoryId'])]
    public function deleteThumbnailImageAction(Request $request, $categoryId)
    {
        try {
            $this->dispatchCommand(new DeleteCategoryThumbnailImageCommand((int) $categoryId));

            $this->addFlash(
                'success',
                $this->trans('Image successfully deleted.', [], 'Admin.Notifications.Success')
            );
        } catch (CategoryException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_categories_edit', [
            'categoryId' => $categoryId,
        ]);
    }

    /**
     * Toggle category status.
     *
     * @param int $categoryId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to update this.')]
    public function toggleStatusAction($categoryId)
    {
        if ($this->isDemoModeEnabled()) {
            return $this->json([
                'status' => false,
                'message' => $this->getDemoModeErrorMessage(),
            ]);
        }

        try {
            $isEnabled = $this->dispatchQuery(new GetCategoryIsEnabled((int) $categoryId));

            $this->dispatchCommand(
                new SetCategoryIsEnabledCommand((int) $categoryId, !$isEnabled)
            );

            $response = [
                'status' => true,
                'message' => $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success'),
            ];
        } catch (CategoryException $e) {
            $response = [
                'status' => false,
                'message' => $this->getErrorMessageForException($e, $this->getErrorMessages()),
            ];
        }

        return $this->json($response);
    }

    /**
     * Process bulk action for categories status enabling.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_categories_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('create', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_categories_index', message: 'You do not have permission to update this.')]
    public function bulkEnableStatusAction(Request $request)
    {
        try {
            $categoryIds = $this->getBulkCategoriesFromRequest($request);

            $command = new BulkEnableCategoriesCommand($categoryIds);

            $this->dispatchCommand($command);

            $this->addFlash(
                'success',
                $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success')
            );
        } catch (CategoryException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_categories_index');
    }

    /**
     * Process bulk action for categories status disabling.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_categories_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('create', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_categories_index', message: 'You do not have permission to update this.')]
    public function bulkDisableStatusAction(Request $request)
    {
        try {
            $categoryIds = $this->getBulkCategoriesFromRequest($request);

            $command = new BulkDisableCategoriesCommand($categoryIds);

            $this->dispatchCommand($command);

            $this->addFlash(
                'success',
                $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success')
            );
        } catch (CategoryException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_categories_index');
    }

    /**
     * Processes bulk categories deleting.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_categories_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_categories_index', message: 'You do not have permission to delete this.')]
    public function bulkDeleteAction(Request $request)
    {
        $deleteCategoriesForm = $this->createForm(DeleteCategoriesType::class);
        $deleteCategoriesForm->handleRequest($request);
        $idParent = (int) $this->getConfiguration()->get('PS_HOME_CATEGORY');

        if ($deleteCategoriesForm->isSubmitted()) {
            try {
                $categoriesDeleteData = $deleteCategoriesForm->getData();
                $idParent = (int) $categoriesDeleteData['categories_to_delete_parent'];
                $categoryIds = array_map(function ($categoryId) {
                    return (int) $categoryId;
                }, $categoriesDeleteData['categories_to_delete']);

                $command = new BulkDeleteCategoriesCommand(
                    $categoryIds,
                    $categoriesDeleteData['delete_mode']
                );

                $this->dispatchCommand($command);

                $this->addFlash(
                    'success',
                    $this->trans('The selection has been successfully deleted.', [], 'Admin.Notifications.Success')
                );
            } catch (CategoryException $e) {
                $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
            }
        }

        return $this->redirectToRoute('admin_categories_index', ['categoryId' => $idParent]);
    }

    /**
     * Process single category deleting.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_categories_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_categories_index', message: 'You do not have permission to delete this.')]
    public function deleteAction(Request $request)
    {
        $deleteCategoriesForm = $this->createForm(DeleteCategoriesType::class);
        $deleteCategoriesForm->handleRequest($request);
        $idParent = (int) $this->getConfiguration()->get('PS_HOME_CATEGORY');

        if ($deleteCategoriesForm->isSubmitted()) {
            $categoriesDeleteData = $deleteCategoriesForm->getData();
            $idParent = (int) $categoriesDeleteData['categories_to_delete_parent'];

            try {
                $command = new DeleteCategoryCommand(
                    (int) reset($categoriesDeleteData['categories_to_delete']),
                    $categoriesDeleteData['delete_mode']
                );

                $this->dispatchCommand($command);

                $this->addFlash('success', $this->trans('Successful deletion', [], 'Admin.Notifications.Success'));
            } catch (CategoryException $e) {
                $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
            }
        }

        return $this->redirectToRoute('admin_categories_index', ['categoryId' => $idParent]);
    }

    /**
     * Export filtered categories.
     *
     * @param CategoryFilters $filters
     *
     * @return Response
     */
    #[DemoRestricted(redirectRoute: 'admin_categories_index')]
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller')) && is_granted('update', request.get('_legacy_controller')) && is_granted('create', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_categories_index', message: 'You do not have permission to view this.')]
    public function exportAction(
        CategoryFilters $filters,
        #[Autowire(service: 'prestashop.core.grid.factory.category')]
        GridFactoryInterface $categoriesGridFactory
    ): Response {
        $filters = new CategoryFilters(['limit' => null] + $filters->all());
        $categoriesGrid = $categoriesGridFactory->getGrid($filters);

        $headers = [
            'id_category' => $this->trans('ID', [], 'Admin.Global'),
            'name' => $this->trans('Name', [], 'Admin.Global'),
            'description' => $this->trans('Description', [], 'Admin.Global'),
            'position' => $this->trans('Position', [], 'Admin.Global'),
            'active' => $this->trans('Displayed', [], 'Admin.Global'),
        ];

        $data = [];

        foreach ($categoriesGrid->getData()->getRecords()->all() as $record) {
            $data[] = [
                'id_category' => $record['id_category'],
                'name' => $record['name'],
                'description' => $record['description'],
                'position' => $record['position'],
                'active' => $record['active'],
            ];
        }

        return (new CsvResponse())
            ->setData($data)
            ->setHeadersData($headers)
            ->setFileName('category_' . date('Y-m-d_His') . '.csv');
    }

    /**
     * Updates category position
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_categories_index')]
    public function updatePositionAction(Request $request)
    {
        try {
            $this->dispatchCommand(new UpdateCategoryPositionCommand(
                $request->request->getInt('id_category_to_move'),
                $request->request->getInt('id_category_parent'),
                $request->request->getInt('way'),
                $request->request->all('positions'),
                $request->request->getBoolean('found_first')
            ));
        } catch (CategoryException $e) {
            return $this->json([
                'success' => false,
                'message' => $this->getErrorMessageForException($e, $this->getErrorMessages()),
            ]);
        }

        return $this->json([
            'success' => true,
            'message' => $this->trans('Successful update', [], 'Admin.Notifications.Success'),
        ]);
    }

    /**
     * Get Categories formatted like ajax_product_file.php.
     *
     * @param int $limit
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller')) || is_granted('create', 'AdminProducts')")]
    public function getAjaxCategoriesAction(
        int $limit,
        Request $request,
        #[Autowire(service: 'prestashop.adapter.data_provider.category')]
        CategoryDataProvider $categoryDataProvider
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw new NotFoundHttpException('Should be ajax request.');
        }

        return new JsonResponse(
            $categoryDataProvider->getAjaxCategories($request->get('query'), $limit, true)
        );
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller')) || is_granted('create', 'AdminProducts')")]
    public function getCategoriesTreeAction(Request $request): JsonResponse
    {
        $langId = $request->query->getInt('langId') ?: (int) $this->getLanguageContext()->getId();
        $categoriesTree = $this->dispatchQuery(new GetCategoriesTree($langId, $this->getShopContext()->getId()));

        return $this->json($this->formatCategoriesTreeForPresentation($categoriesTree, $langId));
    }

    /**
     * @param CategoryForTree[] $categoriesTree
     * @param int $langId
     *
     * @return array
     */
    private function formatCategoriesTreeForPresentation(array $categoriesTree, int $langId): array
    {
        if (empty($categoriesTree)) {
            return [];
        }

        $formattedCategories = [];
        foreach ($categoriesTree as $categoryForTree) {
            $children = $this->formatCategoriesTreeForPresentation($categoryForTree->getChildren(), $langId);

            $active = $categoryForTree->getActive();
            $formattedCategories[] = [
                'id' => $categoryForTree->getCategoryId(),
                'active' => $active,
                'name' => $categoryForTree->getName(),
                'displayName' => $categoryForTree->getDisplayName(),
                'children' => $children,
            ];
        }

        return $formattedCategories;
    }

    /**
     * @param Request $request
     * @param int $categoryId
     *
     * @return array
     */
    private function getCategoryIndexToolbarButtons(Request $request, int $categoryId): array
    {
        $toolbarButtons = [];

        $toolbarButtons['edit'] = [
            'href' => $this->generateUrl('admin_categories_edit', ['categoryId' => $categoryId]),
            'desc' => $this->trans('Edit category', [], 'Admin.Catalog.Feature'),
            'icon' => 'mode_edit',
        ];

        if ($this->getShopContext()->isMultiShopEnabled()) {
            $toolbarButtons['add_root'] = [
                'href' => $this->generateUrl('admin_categories_create_root'),
                'desc' => $this->trans('Add new root category', [], 'Admin.Catalog.Feature'),
                'icon' => 'add_circle_outline',
            ];
        }

        if ($categoryId === 0) {
            $categoryId = $request->attributes->get('categoryId');
        }
        if (empty($categoryId)) {
            $categoryId = (int) $this->getConfiguration()->get('PS_HOME_CATEGORY');
        }

        // Display the button "Add new category" if the current category is not a root category
        $category = new Category($categoryId);
        if (!$category->isRootCategory()) {
            $toolbarButtons['add'] = [
                'href' => $this->generateUrl('admin_categories_create', ['id_parent' => $categoryId]),
                'desc' => $this->trans('Add new category', [], 'Admin.Catalog.Feature'),
                'icon' => 'add_circle_outline',
            ];
        }

        return $toolbarButtons;
    }

    /**
     * Get translated error messages for category exceptions
     *
     * @return array
     */
    private function getErrorMessages()
    {
        return [
            CannotDeleteImageException::class => $this->trans('Unable to delete associated images.', [], 'Admin.Notifications.Error'),
            CategoryNotFoundException::class => $this->trans('The object cannot be loaded (or found).', [], 'Admin.Notifications.Error'),
            CategoryConstraintException::class => [
                CategoryConstraintException::EMPTY_BULK_DELETE_DATA => $this->trans('You must select at least one element to delete.', [], 'Admin.Notifications.Error'),
            ],
            CannotDeleteRootCategoryForShopException::class => $this->trans(
                'You cannot remove this category because one of your shops uses it as a root category.',
                [],
                'Admin.Catalog.Notification'
            ),
            CannotAddCategoryException::class => $this->trans(
                'An error occurred while creating the category.',
                [],
                'Admin.Catalog.Notification'
            ),
            CannotEditRootCategoryException::class => $this->trans(
                'The root category of a shop cannot be edited.',
                [],
                'Admin.Catalog.Notification'
            ),
            CannotEditCategoryException::class => $this->trans(
                'An error occurred while editing the category.',
                [],
                'Admin.Catalog.Notification'
            ),
            CannotUpdateCategoryStatusException::class => $this->trans(
                'An error occurred while updating the status for an object.',
                [],
                'Admin.Notifications.Error'
            ),
            UploadedImageConstraintException::class => sprintf(
                '%s %s',
                $this->trans('An error occurred while uploading the image:', [], 'Admin.Catalog.Notification'),
                $this->trans(
                    'Image format not recognized, allowed formats are: %s',
                    [implode(', ', ImageManager::EXTENSIONS_SUPPORTED)],
                    'Admin.Notifications.Error'
                )
            ),
        ];
    }

    /**
     * @param Request $request
     *
     * @return array
     */
    private function getBulkCategoriesFromRequest(Request $request)
    {
        $categoryIds = $request->request->all('category_id_category');

        foreach ($categoryIds as $i => $categoryId) {
            $categoryIds[$i] = (int) $categoryId;
        }

        return $categoryIds;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    private function requestHasSearchParameters(Request $request)
    {
        return !empty($request->query->all()[CategoryGridDefinitionFactory::GRID_ID]['filters']);
    }
}
