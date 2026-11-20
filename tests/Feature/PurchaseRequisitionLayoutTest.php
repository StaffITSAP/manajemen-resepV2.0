<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema as FilamentSchema;
use Tests\TestCase;

class PurchaseRequisitionLayoutTest extends TestCase
{
    public function test_create_and_edit_form_sections_span_the_full_content_width(): void
    {
        $sections = PurchaseRequisitionResource::form(FilamentSchema::make())->getComponents();

        $this->assertCount(2, $sections);
        $this->assertInstanceOf(Section::class, $sections[0]);
        $this->assertInstanceOf(Section::class, $sections[1]);
        $this->assertSame('full', $sections[0]->getColumnSpan('default'));
        $this->assertSame('full', $sections[1]->getColumnSpan('default'));
        $this->assertSame(2, $sections[0]->getColumns('lg'));
    }

    public function test_view_infolist_sections_span_the_full_content_width(): void
    {
        $sections = PurchaseRequisitionResource::infolist(FilamentSchema::make())->getComponents(withHidden: true);

        $this->assertCount(3, $sections);
        $this->assertInstanceOf(Section::class, $sections[0]);
        $this->assertInstanceOf(Section::class, $sections[1]);
        $this->assertInstanceOf(Section::class, $sections[2]);
        $this->assertSame('full', $sections[0]->getColumnSpan('default'));
        $this->assertSame('full', $sections[1]->getColumnSpan('default'));
        $this->assertSame('full', $sections[2]->getColumnSpan('default'));
    }
}
