<?php

/*
 * This file is part of the "news" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace GeorgRinger\News\Domain\Repository;

use GeorgRinger\News\Domain\Model\Category;
use GeorgRinger\News\Domain\Model\DemandInterface;
use GeorgRinger\News\Service\CategoryService;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Category repository with all callable functionality
 */
class CategoryRepository extends AbstractDemandedRepository
{
    protected function createConstraintsFromDemand(QueryInterface $query, DemandInterface $demand): array
    {
        return [];
    }

    protected function createOrderingsFromDemand(DemandInterface $demand): array
    {
        return [];
    }

    /**
     * Find category by import source and import id
     *
     * @param string $importSource import source
     * @param string $importId import id
     * @param bool $asArray return result as array
     *
     * @return Category|array
     */
    public function findOneByImportSourceAndImportId($importSource, $importId, $asArray = false)
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->getQuerySettings()->setIgnoreEnableFields(true);

        $result = $query->matching(
            $query->logicalAnd(
                $query->equals('importSource', $importSource),
                $query->equals('importId', $importId)
            )
        )->execute($asArray);
        if ($asArray) {
            return $result[0] ?? [];
        }
        return $result->getFirst();
    }

    /**
     * Find categories by a given pid
     *
     * @param int $pid pid
     *
     * @return QueryResultInterface<Category>
     */
    public function findParentCategoriesByPid($pid)
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        return $query->matching(
            $query->logicalAnd(
                $query->equals('pid', (int)$pid),
                $query->equals('parentcategory', 0)
            )
        )->execute();
    }

    /**
     * Find category tree
     *
     * @param array $rootIdList list of ids
     *
     * @return QueryInterface|array
     */
    public function findTree(array $rootIdList, ?string $startingPoint = null): array
    {
        $subCategories = CategoryService::getChildrenCategories(implode(',', $rootIdList));

        $idList = explode(',', $subCategories);
        if (empty($idList)) {
            return [];
        }

        $ordering = ['sorting' => QueryInterface::ORDER_ASCENDING];
        $categories = $this->findByIdList($idList, $ordering, $startingPoint);
        $flatCategories = [];
        /** @var Category $category */
        foreach ($categories as $category) {
            $flatCategories[$category->getUid()] = [
                'item' => $category,
                'parent' => ($category->getParentcategory()) ? $category->getParentcategory()->getUid() : null,
            ];
        }

        $tree = [];

        // If leaves are selected without its parents selected, those are shown as parent
        foreach ($flatCategories as $id => &$flatCategory) {
            if (!isset($flatCategories[$flatCategory['parent']])) {
                $flatCategory['parent'] = null;
            }
        }

        foreach ($flatCategories as $id => &$node) {
            if ($node['parent'] === null) {
                $tree[$id] = &$node;
            } else {
                $flatCategories[$node['parent']]['children'][$id] = &$node;
            }
        }

        return $tree;
    }

    /**
     * Find categories by a given pid
     *
     * @param array $idList list of ids
     * @param array $ordering ordering
     *
     * @return QueryResultInterface<Category>
     */
    public function findByIdList(array $idList, array $ordering = [], ?string $startingPoint = null)
    {
        if (empty($idList)) {
            throw new \InvalidArgumentException('The given id list is empty.', 1484823597);
        }
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->getQuerySettings()->setRespectSysLanguage(true);

        if (count($ordering) > 0) {
            $query->setOrderings($ordering);
        }
        $this->overlayTranslatedCategoryIds($idList);

        $conditions = [];
        $conditions[] = $query->in('uid', $idList);

        if (is_null($startingPoint) === false) {
            $conditions[] = $query->in('pid', GeneralUtility::trimExplode(',', $startingPoint, true));
        }

        return $query->matching(
            $query->logicalAnd(...$conditions)
        )->execute();
    }

    /**
     * Find categories by a given parent
     *
     * @param int $parent parent
     *
     * @return QueryResultInterface<Category>
     */
    public function findChildren($parent)
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->setOrderings(['sorting' => QueryInterface::ORDER_ASCENDING]);
        return $query->matching(
            $query->logicalAnd(
                $query->equals('parentcategory', (int)$parent)
            )
        )->execute();
    }

    /**
     * Overlay the category ids with the ones from current language
     *
     * @param array $idList
     * return void
     */
    protected function overlayTranslatedCategoryIds(array &$idList): void
    {
        $language = $this->getSysLanguageUid();
        if ($language > 0 && !empty($idList)) {
            if (isset($GLOBALS['TSFE']) && is_object($GLOBALS['TSFE'])) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('sys_category');
                $rows = $queryBuilder
                    ->select('l10n_parent', 'uid', 'sys_language_uid')
                    ->from('sys_category')
                    ->where(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language, Connection::PARAM_INT)),
                        $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter($idList, Connection::PARAM_INT_ARRAY))
                    )
                    ->executeQuery()->fetchAllAssociative();

                $idList = $this->replaceCategoryIds($idList, $rows);
            }
            // @todo currently only implemented for the frontend
        }
    }

    /**
     * Get the current sys language uid
     */
    protected function getSysLanguageUid(): int
    {
        $sysLanguage = 0;

        if (isset($GLOBALS['TSFE']) && is_object($GLOBALS['TSFE'])) {
            $sysLanguage = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('language', 'contentId');
        } elseif ((int)($GLOBALS['TYPO3_REQUEST']->getParsedBody()['L'] ?? $GLOBALS['TYPO3_REQUEST']->getQueryParams()['L'] ?? null)) {
            $sysLanguage = (int)($GLOBALS['TYPO3_REQUEST']->getParsedBody()['L'] ?? $GLOBALS['TYPO3_REQUEST']->getQueryParams()['L'] ?? null);
        }

        return $sysLanguage;
    }

    /**
     * Replace ids in array by the given ones
     */
    protected function replaceCategoryIds(array $idList, array $rows): array
    {
        foreach ($rows as $row) {
            $pos = array_search($row['l10n_parent'], $idList);
            if ($pos !== false) {
                $idList[$pos] = (int)$row['uid'];
            }
        }

        return $idList;
    }
}
