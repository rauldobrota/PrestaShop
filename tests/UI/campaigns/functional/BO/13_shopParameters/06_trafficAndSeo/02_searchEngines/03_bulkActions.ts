// Import utils
import testContext from '@utils/testContext';

// Import pages
import seoAndUrlsPage from '@pages/BO/shopParameters/trafficAndSeo/seoAndUrls';
import searchEnginesPage from '@pages/BO/shopParameters/trafficAndSeo/searchEngines';
import addSearchEnginePage from '@pages/BO/shopParameters/trafficAndSeo/searchEngines/add';

import {expect} from 'chai';
import {
  boDashboardPage,
  boLoginPage,
  type BrowserContext,
  FakerSearchEngine,
  type Page,
  utilsPlaywright,
} from '@prestashop-core/ui-testing';

const baseContext: string = 'functional_BO_shopParameters_trafficAndSeo_searchEngines_bulkActions';

/*
Create 2 search engine
Delete with bulk actions
 */
describe('BO - Shop Parameters - Traffic & SEO : Bulk delete search engine', async () => {
  let browserContext: BrowserContext;
  let page: Page;
  let numberOfSearchEngines: number = 0;

  // before and after functions
  before(async function () {
    browserContext = await utilsPlaywright.createBrowserContext(this.browser);
    page = await utilsPlaywright.newTab(browserContext);
  });

  after(async () => {
    await utilsPlaywright.closeBrowserContext(browserContext);
  });

  it('should login in BO', async function () {
    await testContext.addContextItem(this, 'testIdentifier', 'loginBO', baseContext);

    await boLoginPage.goTo(page, global.BO.URL);
    await boLoginPage.successLogin(page, global.BO.EMAIL, global.BO.PASSWD);

    const pageTitle = await boDashboardPage.getPageTitle(page);
    expect(pageTitle).to.contains(boDashboardPage.pageTitle);
  });

  it('should go to \'Shop Parameters > Traffic & SEO\' page', async function () {
    await testContext.addContextItem(this, 'testIdentifier', 'goToSeoAndUrlsPage', baseContext);

    await boDashboardPage.goToSubMenu(
      page,
      boDashboardPage.shopParametersParentLink,
      boDashboardPage.trafficAndSeoLink,
    );

    await seoAndUrlsPage.closeSfToolBar(page);

    const pageTitle = await seoAndUrlsPage.getPageTitle(page);
    expect(pageTitle).to.contain(seoAndUrlsPage.pageTitle);
  });

  it('should go to \'Search Engines\' pge', async function () {
    await testContext.addContextItem(this, 'testIdentifier', 'goToSearchEnginesPage', baseContext);

    await seoAndUrlsPage.goToSearchEnginesPage(page);

    const pageTitle = await searchEnginesPage.getPageTitle(page);
    expect(pageTitle).to.contain(searchEnginesPage.pageTitle);
  });

  it('should reset all filters and get number of search engines in BO', async function () {
    await testContext.addContextItem(this, 'testIdentifier', 'resetFilterFirst', baseContext);

    numberOfSearchEngines = await searchEnginesPage.resetAndGetNumberOfLines(page);
    expect(numberOfSearchEngines).to.be.above(0);
  });

  const creationTests: number[] = new Array(2).fill(0, 0, 2);

  creationTests.forEach((test: number, index: number) => {
    describe(`Create search engine n°${index + 1}`, async () => {
      const searchEngineData: FakerSearchEngine = new FakerSearchEngine({server: `todelete${index}`});
      it('should go to new search engine', async function () {
        await testContext.addContextItem(this, 'testIdentifier', `goToNewSearchEnginePage${index}`, baseContext);

        await searchEnginesPage.goToNewSearchEnginePage(page);

        const pageTitle = await addSearchEnginePage.getPageTitle(page);
        expect(pageTitle).to.contain(addSearchEnginePage.pageTitleCreate);
      });

      it('should create search engine', async function () {
        await testContext.addContextItem(this, 'testIdentifier', `createSearchEngine${index}`, baseContext);

        const result = await addSearchEnginePage.createEditSearchEngine(page, searchEngineData);
        expect(result).to.contain(searchEnginesPage.successfulCreationMessage);

        const numberOfSearchEnginesAfterCreation = await searchEnginesPage.getNumberOfElementInGrid(page);
        expect(numberOfSearchEnginesAfterCreation).to.equal(numberOfSearchEngines + 1 + index);
      });
    });
  });

  describe('Delete search engine by bulk actions', async () => {
    it('should filter by server', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'filterToBulkDelete', baseContext);

      await searchEnginesPage.filterTable(page, 'server', 'toDelete');

      const numberOfSearchEnginesAfterFilter = await searchEnginesPage.getNumberOfElementInGrid(page);
      expect(numberOfSearchEnginesAfterFilter).to.be.at.least(2);

      const textColumn = await searchEnginesPage.getTextColumn(page, 1, 'server');
      expect(textColumn).to.contain('todelete');
    });

    it('should delete search engine', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'deleteSearchEngine', baseContext);

      // delete search engine in first row
      const result = await searchEnginesPage.bulkDeleteSearchEngine(page);
      expect(result).to.be.contain(searchEnginesPage.successfulMultiDeleteMessage);
    });

    it('should reset filter and check number of searchEngine', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'resetAfterDelete', baseContext);

      const numberOfSearchEnginesAfterDelete = await searchEnginesPage.resetAndGetNumberOfLines(page);
      expect(numberOfSearchEnginesAfterDelete).to.equal(numberOfSearchEngines);
    });
  });
});
