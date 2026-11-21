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
        $this->assertSame('pr-form-section pr-form-section--info', $sections[0]->getExtraAttributes()['class']);
        $this->assertSame('pr-form-section pr-form-section--items', $sections[1]->getExtraAttributes()['class']);

    }

    public function test_form_stylesheet_is_registered_as_a_scoped_static_panel_asset(): void
    {
        $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $stylesheet = file_get_contents(public_path('css/purchase-requisition-forms.css'));

        $this->assertStringContainsString("Css::make('purchase-requisition-forms')", $provider);
        $this->assertStringContainsString("relativePublicPath('css/purchase-requisition-forms.css')", $provider);
        $this->assertStringContainsString('.pr-form-section > .fi-section', $stylesheet);
        $this->assertStringNotContainsString("\n.fi-section {", $stylesheet);
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
