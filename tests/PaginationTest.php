<?php
/**
 * اختبارات فئة Pagination
 * - حسابات Offset / Limit
 * - الصفحات المرئية (مع ...)
 * - حالات الحدود (صفحة واحدة، كثيرة الصفحات)
 * - تحويل المصفوفة toArray()
 */

class PaginationTest extends TestCase
{
    // === اختبارات أساسية ===

    public function testBasicPagination(): void
    {
        $p = new Pagination(100, 20, 1);
        $this->assertEqual(100, $p->getTotal());
        $this->assertEqual(20, $p->getPerPage());
        $this->assertEqual(1, $p->getCurrentPage());
        $this->assertEqual(5, $p->getTotalPages());
    }

    public function testOffsetCalculation(): void
    {
        $p = new Pagination(100, 20, 3);
        $this->assertEqual(40, $p->getOffset());
    }

    public function testOffsetFirstPage(): void
    {
        $p = new Pagination(100, 20, 1);
        $this->assertEqual(0, $p->getOffset());
    }

    public function testLimit(): void
    {
        $p = new Pagination(100, 25, 1);
        $this->assertEqual(25, $p->getLimit());
    }

    // === اختبارات hasNext / hasPrev ===

    public function testHasNext(): void
    {
        $p = new Pagination(100, 20, 1);
        $this->assertTrue($p->hasNext());
    }

    public function testHasNotNextOnLastPage(): void
    {
        $p = new Pagination(100, 20, 5);
        $this->assertFalse($p->hasNext());
    }

    public function testHasPrev(): void
    {
        $p = new Pagination(100, 20, 3);
        $this->assertTrue($p->hasPrev());
    }

    public function testHasNotPrevOnFirstPage(): void
    {
        $p = new Pagination(100, 20, 1);
        $this->assertFalse($p->hasPrev());
    }

    // === اختبارات الصفحات المرئية ===

    public function testVisiblePagesSmall(): void
    {
        $p = new Pagination(50, 10, 1, '', 'page', 5);
        $pages = $p->getVisiblePages();
        $this->assertEqual(range(1, 5), $pages);
    }

    public function testVisiblePagesExactMaxVisible(): void
    {
        // 7 صفحات مع maxVisible=5 → يجب أن تعرض الكل (+2)
        $p = new Pagination(70, 10, 3, '', 'page', 5);
        $pages = $p->getVisiblePages();
        $this->assertEqual(range(1, 7), $pages);
    }

    public function testVisiblePagesWithManyEllipsis(): void
    {
        $p = new Pagination(200, 10, 10, '', 'page', 5);
        $pages = $p->getVisiblePages();
        // يجب أن يبدأ بـ 1 وينتهي بـ 20
        $this->assertEqual(1, $pages[0]);
        $this->assertEqual(20, $pages[count($pages) - 1]);
        // يجب أن يحتوي على ...
        $this->assertTrue(in_array('...', $pages));
    }

    public function testVisiblePagesFirstPage(): void
    {
        $p = new Pagination(100, 10, 1, '', 'page', 5);
        $pages = $p->getVisiblePages();
        $this->assertEqual(1, $pages[0]);
        $this->assertEqual(10, $pages[count($pages) - 1]);
    }

    public function testVisiblePagesLastPage(): void
    {
        $p = new Pagination(100, 10, 10, '', 'page', 5);
        $pages = $p->getVisiblePages();
        $this->assertEqual(1, $pages[0]);
        $this->assertEqual(10, $pages[count($pages) - 1]);
    }

    public function testVisiblePagesMiddle(): void
    {
        $p = new Pagination(100, 10, 5, '', 'page', 5);
        $pages = $p->getVisiblePages();
        $this->assertEqual(1, $pages[0]);
        $this->assertEqual(10, $pages[count($pages) - 1]);
        $this->assertTrue(in_array(5, $pages));
    }

    // === اختبارات getNextPage / getPrevPage ===

    public function testGetNextPage(): void
    {
        $p = new Pagination(100, 20, 2);
        $this->assertEqual(3, $p->getNextPage());
    }

    public function testGetNextPageNullOnLast(): void
    {
        $p = new Pagination(100, 20, 5);
        $this->assertNull($p->getNextPage());
    }

    public function testGetPrevPage(): void
    {
        $p = new Pagination(100, 20, 3);
        $this->assertEqual(2, $p->getPrevPage());
    }

    public function testGetPrevPageNullOnFirst(): void
    {
        $p = new Pagination(100, 20, 1);
        $this->assertNull($p->getPrevPage());
    }

    // === حالات الحدود ===

    public function testZeroTotal(): void
    {
        $p = new Pagination(0, 20, 1);
        $this->assertEqual(1, $p->getTotalPages());
        $this->assertEqual(0, $p->getOffset());
        $this->assertFalse($p->hasNext());
        $this->assertFalse($p->hasPrev());
    }

    public function testOneItem(): void
    {
        $p = new Pagination(1, 20, 1);
        $this->assertEqual(1, $p->getTotalPages());
        $this->assertFalse($p->hasNext());
        $this->assertFalse($p->hasPrev());
    }

    public function testExactMultiple(): void
    {
        $p = new Pagination(60, 20, 3);
        $this->assertEqual(3, $p->getTotalPages());
        $this->assertFalse($p->hasNext());
        $this->assertTrue($p->hasPrev());
    }

    public function testNotExactMultiple(): void
    {
        $p = new Pagination(61, 20, 4);
        $this->assertEqual(4, $p->getTotalPages());
        $this->assertFalse($p->hasNext());
    }

    public function testPageExceedsTotalClamped(): void
    {
        $p = new Pagination(10, 5, 100);
        $this->assertEqual(2, $p->getCurrentPage());
    }

    public function testNegativeTotalClampedToZero(): void
    {
        $p = new Pagination(-5, 20, 1);
        $this->assertEqual(0, $p->getTotal());
    }

    // === اختبارات toArray() ===

    public function testToArrayStructure(): void
    {
        $p = new Pagination(100, 20, 3);
        $arr = $p->toArray();
        $this->assertIsArray($arr);
        $this->assertArrayHasKey('total', $arr);
        $this->assertArrayHasKey('per_page', $arr);
        $this->assertArrayHasKey('current_page', $arr);
        $this->assertArrayHasKey('total_pages', $arr);
        $this->assertArrayHasKey('has_next', $arr);
        $this->assertArrayHasKey('has_prev', $arr);
        $this->assertArrayHasKey('offset', $arr);
    }

    public function testToArrayValues(): void
    {
        $p = new Pagination(100, 20, 3);
        $arr = $p->toArray();
        $this->assertEqual(100, $arr['total']);
        $this->assertEqual(20, $arr['per_page']);
        $this->assertEqual(3, $arr['current_page']);
        $this->assertEqual(5, $arr['total_pages']);
        $this->assertTrue($arr['has_next']);
        $this->assertTrue($arr['has_prev']);
        $this->assertEqual(40, $arr['offset']);
    }

    // === اختبارات PerPage Bounds ===

    public function testPerPageMax100(): void
    {
        $p = new Pagination(1000, 200, 1);
        $this->assertEqual(100, $p->getPerPage());
    }

    public function testPerPageMin1(): void
    {
        $p = new Pagination(100, 0, 1);
        $this->assertEqual(1, $p->getPerPage());
    }

    public function testPerPageNegativeClampedTo1(): void
    {
        $p = new Pagination(100, -10, 1);
        $this->assertEqual(1, $p->getPerPage());
    }

    // === اختبار renderHtml ===

    public function testRenderHtmlEmptyForSinglePage(): void
    {
        $p = new Pagination(5, 20, 1);
        $html = $p->renderHtml();
        $this->assertEqual('', $html);
    }

    public function testRenderHtmlNotEmptyForMultiplePages(): void
    {
        $p = new Pagination(100, 20, 2);
        $html = $p->renderHtml();
        $this->assertStringContains('pagination-container', $html);
        $this->assertStringContains('pagination-prev', $html);
        $this->assertStringContains('pagination-next', $html);
    }

    public function testRenderHtmlContainsPageNumbers(): void
    {
        $p = new Pagination(100, 20, 2);
        $html = $p->renderHtml();
        $this->assertStringContains('data-page="1"', $html);
        $this->assertStringContains('data-page="2"', $html);
    }

    // === اختبار renderInfo ===

    public function testRenderInfoWithResults(): void
    {
        $p = new Pagination(100, 20, 2);
        $info = $p->renderInfo();
        $this->assertStringContains('21 - 40', $info);
        $this->assertStringContains('100', $info);
    }

    public function testRenderInfoNoResults(): void
    {
        $p = new Pagination(0, 20, 1);
        $info = $p->renderInfo();
        $this->assertStringContains('لا توجد', $info);
    }

    // === اختبار getStyles ===

    public function testGetStylesNotEmpty(): void
    {
        $styles = Pagination::getStyles();
        $this->assertStringContains('.pagination-container', $styles);
        $this->assertStringContains('.pagination-btn', $styles);
    }
}
