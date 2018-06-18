<div id="automater" class="panel product-tab">
    <input type="hidden" name="submitted_tabs[]" value="Automater Mapping" />
    <h3>{l s='Map product with product from Automater' mod='automater'}</h3>

    <div class="row">
        <div class="col-lg-4">
            <label class="control-label">{l s="Select associated product from Automater" mod='automater'}</label>
        </div>
        <div class="col-lg-8">
            <select name="selautomater_product_id"  id="selautomater_product_id"  style="width:100%;" data-toggle="select2">
                <option value="0">{l s="--- select value ---" mod='automater'}</option>
                {foreach from=$automaterProducts item=product}
                    <option value="{$product->getId()}" {if $assignedProductId == $product->getId()}selected="selected"{/if}>ID {$product->getId()}: {$product->getName()}</option>
                {/foreach}
            </select>
        </div>
    </div>

    <div class="panel-footer">
        <a href="{$link->getAdminLink('AdminProducts')|escape:'html':'UTF-8'}" class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Cancel'}</a>
        <button type="submit" name="submitAddproduct" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save'}</button>
        <button type="submit" name="submitAddproductAndStay" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save and stay'}</button>
    </div>
</div>
<script language="javascript" type="text/javascript">
    $(function() {
        tinySetup();
    });
</script>