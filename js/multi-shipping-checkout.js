jQuery(function($){

//
// script for multi package group handling
// ---------------------------------------

    const ns = '.multiBulkMove';

	function rebuildBulkMoveTargets($table){
		const $select = $table.find('.bulk-move-target');
		const $button = $table.find('.bulk-move-button');
		
		let intersection = null;

		$table.find('.bulk-select-item:checked').each(function(){
			const targets = $(this).data('move-targets');
			if (!targets) return;

			// First selected row sets the baseline
			if (intersection === null) {
				intersection = { ...targets };
				return;
			}

			// Remove anything not present in this row
			Object.keys(intersection).forEach(key => {
				if (!(key in targets)) {
					delete intersection[key];
				}
			});
		});

		// Clear existing options
		$select.empty();

		if (!intersection || Object.keys(intersection).length === 0) {
			$select
				.prop('disabled', true)
				.append(
					$('<option>', {
						value: '',
						text: 'Select an item first',
						disabled: true,
						selected: true
					})
				);
			$button.addClass('hidden', true).prop('dis', true);				
			return;
		}		
		
		$select.prop('disabled', false);
		$button.prop('dis', false).removeClass('hidden', true);				
		
		
		
		// Populate merged options
		Object.entries(intersection).forEach(([value, label]) => {
			$select.append(
				$('<option>', {
					value: value,
					text: label
				})
			);
		});
	}


    function attachBulkHandlers(){
        // remove previously bound handlers (safe rebind on fragment updates)
        $(document).off('click' + ns, '.bulk-move-button');
        $(document).off('change' + ns, '.select-all-in-group');
        $(document).off('click' + ns, '.bulk-select-item');

        // select all in group
        $(document).on('change' + ns, '.select-all-in-group', function(){
            const groupTable = $(this).closest('table');
            const group = $(this).data('group');
            const checked = $(this).prop('checked');
            $('.bulk-select-item[data-group="'+group+'"], .bulk-select-item').each(function(){
                // only check items in same group (we rely on row data-group)
            });
            // simpler: iterate rows in this group table
            groupTable.find('input.bulk-select-item').prop('checked', checked);
			rebuildBulkMoveTargets(groupTable);
        });

        // individual item click - nothing fancy needed but keep for future
        $(document).on('click' + ns, '.bulk-select-item', function(){
            // optional: toggle group-level "select all" if all checked
            const groupTable = $(this).closest('table');
            const all = groupTable.find('.bulk-select-item').length;
            const checked = groupTable.find('.bulk-select-item:checked').length;
            groupTable.find('.select-all-in-group').prop('checked', all===checked);
			rebuildBulkMoveTargets(groupTable);
        });

        // bulk move button
        $(document).on('click' + ns, '.bulk-move-button', function(e){
            e.preventDefault();
            const fromGroup = $(this).data('group');
            const $table = $(this).closest('table');
            const target = $table.find('.bulk-move-target').val();
            const selected = [];

            $table.find('.bulk-select-item:checked').each(function(){
                const key = $(this).data('cart_item_key');
                if(key) selected.push(key);
            });

            if(selected.length === 0){
                $(this).closest('td').find('.bulk-move-status').text('No items selected').css('color','red');
                return;
            }

            // visual feedback
            const $status = $(this).closest('td').find('.bulk-move-status');
            $status.text('Moving...').css('color','#444');

            // AJAX POST
            $.post(multiShippingVars.ajax_url, {
                action: 'multi_bulk_move',
                security: multiShippingVars.nonce,
                from_group: fromGroup,
                target_group: target,
                items: selected
            }, function(resp){
                if(resp && resp.success){
                    $status.text(resp.data.message || 'Moved').css('color','green');
                    // refresh checkout to update packages/shipments/totals
                    $('body').trigger('update_checkout');
                } else {
                    $status.text(resp.data && resp.data.message ? resp.data.message : 'Failed').css('color','red');
                }
            }).fail(function(){
                $status.text('Request failed').css('color','red');
            });
        });
    }

	function scheduleQtyCommit($input, delay = 400) {

		// Clear existing timer
		const existing = $input.data('qtyTimeout');
		if (existing) {
			clearTimeout(existing);
		}

		const timeout = setTimeout(function () {
			$input.trigger('change');
		}, delay);

		$input.data('qtyTimeout', timeout);
	}

	function updateSubTotal($input, qty) {
		// Quick local update for instant feedback
		const $row = $input.closest('tr.cart_item');
		const priceText = $row.find('.product-subtotal').data('unitprice');
		if (priceText) {
			const unitPrice = parseFloat(priceText);
			const newTotal = (unitPrice * qty).toFixed(2);
			const $bdi = $row.find('.product-subtotal .woocommerce-Price-amount bdi');

			// Remove everything except the symbol
			$bdi.contents().filter(function () {
				return this.nodeType === Node.TEXT_NODE;
			}).remove();

			$bdi.append(document.createTextNode(newTotal)).css('color', 'green');
		}
	}


	function attachQtyButtons() {

		$(document).off('click.qtyButtons', '.qty-btn');

		$(document).on('click.qtyButtons', '.qty-btn', function (e) {
			e.preventDefault();

			const $btn   = $(this);
			$btn.blur();

			const $wrap  = $btn.closest('.qty-btn-wrap');
			const $input = $wrap.find('input.qty');

			let qty  = parseInt($input.val(), 10) || 0;

			if (($btn.hasClass('qty-plus')  && ++qty==100) || ($btn.hasClass('qty-minus') && --qty==0)) return;
			updateSubTotal($input, qty);
			$input.val(qty); //.trigger('change');
			scheduleQtyCommit($input);
		});
	}

	function cacheQty($input) {
		const val = parseInt($input.val(), 10);
		if (!isNaN(val) && val >= 0) {
			$input.data('last_valid_qty', val);
		}
	}

	function isValidQty(val) { return /^[0-9]+$/.test(val); }

	function attachQtyHandler(){
		// Detach existing handlers first
		$(document).off('change.multiQty', '.woocommerce-checkout-review-order input.qty');

		$(document).on('change.multiQty', '.woocommerce-checkout-review-order input.qty', function(e){
			e.preventDefault();

			const $input = $(this);
			const match = $input.attr('name').match(/\[([^\]]+)\]\[qty\]/);
			if (!match) return;

			const cart_item_key = match[1];
			
			let raw = $input.val().trim();
			const valid = isValidQty(raw);

			let new_qty = 0;
			if (valid) new_qty = parseInt(raw, 10);		
			
			// Not valid or out of range then revert and bail
			if (!valid || new_qty==0 || new_qty>99) {
				const lastValid = $input.data('last_valid_qty');

				if (typeof lastValid !== 'undefined') {
					$input.val(lastValid);
				}

				return;
			}

			updateSubTotal($input, new_qty);
			
			const t = $(e.currentTarget).closest('tr');
			t.block({
				message: null,
				overlayCSS: {background: 'rgba(242,221,176,0.8)',cursor: 'wait'}
			});

			// Debounce to avoid spamming
			if ($input.data('timeout')) clearTimeout($input.data('timeout'));
			$input.data('timeout', setTimeout(function(){

				$('body').addClass('updating-checkout');

				$.ajax({
					type: 'POST',
					url: multiShippingVars.ajax_url,
					data: {
						action: 'woocommerce_update_cart_item',
						security: multiShippingVars.nonce,
						cart_item_key: cart_item_key,
						quantity: new_qty
					},
					success: function(response){
						if (response.success) {
							cacheQty($input);
							$('body').trigger('update_checkout');
						}
					},
					complete: function(){
						t.unblock();
						$('body').removeClass('updating-checkout');
					}
				});

			}, 400));
		});
	}

	function attachDeleteHandler(){
		$(document).off('click.remove', '.woocommerce-checkout-review-order .remove');
		$(document).on('click.remove', '.woocommerce-checkout-review-order .remove', function(e){
			const t = $(e.currentTarget).closest('tr');
			t.block({
				message: null,
				overlayCSS: {background: 'rgba(242,221,176,0.8)',cursor: 'wait'}
			});
			
		});
	}


	function attachGroupToggle(){
		$(document).off('change.multiGroup', '#toggle-shipping-groups');
		$(document).on('change.multiGroup', '#toggle-shipping-groups', function(e){
			
			const t = $(e.currentTarget).closest('tbody');
			t.block({
				message: null,
				overlayCSS: {background: 'rgba(255,255,255,0.8)',cursor: 'wait'}
			});
			
			
			const enabled = $(this).is(':checked');
			$.ajax({
				type: 'POST',
				url: multiShippingVars.ajax_url,
				data: {
					action: 'woocommerce_toggle_shipping_groups',
					security: multiShippingVars.nonce,
					enabled: enabled ? 1 : 0
				},
				success: function(){
					t.unblock();
					$('body').trigger('update_checkout');
				}
			});
		});
	}

// -------- Initial bind & fragment-safe rebind --------
	attachBulkHandlers();
	attachQtyButtons();
	attachQtyHandler();
	attachDeleteHandler();	
	attachGroupToggle();
	
	$(document).on('keydown', '.woocommerce-checkout-review-order input.qty', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			e.stopPropagation();

			$(this).blur(); // commit change naturally
			return false;
		}
	});
	
	
	$(document.body).on('updated_checkout', function(){
		attachBulkHandlers();
		attachQtyButtons();
		attachQtyHandler();
		attachDeleteHandler();			
		attachGroupToggle();
		$('.shop_table[data-group]').each(function(){rebuildBulkMoveTargets($(this));});
	});




//
// script for modal popup & ajax saving of field in front end
// -----------------------------------------------------------------

	function initAllTurqDelivery(){
		// hide input radio if only one shipping option & check it's selected
		$('.woocommerce-shipping-totals.shipping').each(function () {
			const $methodsList = $(this).find('ul.woocommerce-shipping-methods');
			if (!$methodsList.length) return;

			const $methods = $methodsList.children('li');
			if ($methods.length === 1) {
				const $input = $methods.find('input[type="radio"]');
				$input.prop('checked', true).hide();
			}
		});
		
		$('.turq_delivery_trigger').each(function(){
			const groupKey = $(this).data('group');
			initTurqDelivery(groupKey);
		});
	}

	function initTurqDelivery(groupKey){

		const trigger = $(`.turq_delivery_trigger[data-group="${groupKey}"]`);
		const modal = $(`.turq_delivery_modal[data-group="${groupKey}"]`);
		if(!trigger.length || !modal.length) return;

		const openBtn = trigger.find('.turq_delivery_open');
		const summary = trigger.find('.turq_delivery_summary');
		const wrapper = modal.find('.turq_delivery');
		const confirmBtn = modal.find('.modal-confirm');
		const closeBtn = modal.find('.modal-close');
		const mainDIV = $('#main');
		if (!wrapper || !openBtn || !confirmBtn || !modal || !summary) return;


		// -------- Helpers --------
		function updateSummaryText(text){
			if(!text){
				summary.html(summary.data('default')).css('color','red');
			}else{
				summary.html(text).css('color','grey');
			}
		}

		function updateSummary(){
			const selects = wrapper.find('select');
			const values = [];
			selects.each(function(){
				const text = $(this).find('option:selected').text();
				if(text && !/choose/i.test(text)) values.push(text);
			});
			if(values.length) updateSummaryText(wrapper.data('label') + ": " + values.join(' - '));
			else updateSummaryText('');
		}

		function handleMarketChange(sel){
			const marketVal  = $(sel).val();
			const dateSelect = wrapper.find('#ti_date_'+groupKey);
			const ti_dates   = dateSelect.data('ti_dates_data');
			const dateField  = wrapper.find('#ti_date_'+groupKey+'_field');
				
			if (!marketVal){
				dateField.addClass('ti_select_hidden');
				dateSelect.html('');
			} else {
				const optionsArray = [
						{ v: '', o: dateSelect.data('placeholder') },
						...(ti_dates[marketVal] || [])
					];
				
				dateSelect.html(optionsArray.reduce((acc,opt)=>acc+`<option value="${opt.v}">${opt.o}</option>`,""));
				dateField.removeClass('ti_select_hidden');

				// auto-select if only one valid
				const valid = optionsArray.filter(o=>o.v.trim()!=="");
				if(valid.length===1){
					dateSelect.val(valid[0].v);
				}
			}
		}

		// -------- Async save function with Woo spinner --------
		async function saveSelections(selects){
			const data = {};
			data.group = groupKey;
			data.shippingMethod = modal.closest('li').find('input.shipping_method').val();					
			selects.each(function(){ data[this.name] = $(this).val(); });

			// Block modal with Woo spinner
			const contentDiv = modal.find('.modal-content');
			if(contentDiv){
				contentDiv.block({
					message: null,
					overlayCSS: {background: 'rgba(255,255,255,0.8)',cursor: 'wait'}
				});
			}

			try {
				const result = await $.post(multiShippingVars.ajax_url, { action: 'turq_delivery', values: data, 'security': multiShippingVars.nonce});
				if(result.success){ 
					let textContent = '';
					Object.entries(result.data).forEach(([key, value], index) => {
						textContent += `${key}: ${value}`;
						if(index < Object.entries(result.data).length - 1) textContent += ' - '; // optional separator
					});
					console.log('Saved row selections:', textContent);
				}
			} catch(e) {
				console.error('turq_delivery save failed', e);
			} finally {
				updateSummary();
				contentDiv.unblock();
			}
		}							
				
		$(document.body).on('click', '.turq_deliver_error a[href="#turq_delivery_modal_'+groupKey+'"]', function(e) {
			e.preventDefault();
			openBtn.click();
		});
	
		// -------- Modal open/close --------					
		openBtn.on('click', e=>{
			e.preventDefault();
			wrapper.find('select').each(function() {$(this).data('original-value', $(this).val());});
			mainDIV.addClass('turq_modal_active');
			modal.addClass('active');
		});
		
		function restoreClose(){
			wrapper.find('select').each(function() {
				const original = $(this).data('original-value');
				if (original !== undefined) $(this).val(original).trigger('change');
			});
			closeModal();
		}

		function closeModal(){
			modal.removeClass('active');
			modal.find('.ti-error-msg').remove();			
			mainDIV.removeClass('turq_modal_active');
		}

		closeBtn.on('click', restoreClose);
		modal.on('click', e=>{if(e.target === modal[0]) restoreClose();});		
		$(document).on('keydown', function(e){ if(e.key === "Escape") restoreClose(); });
		
		confirmBtn.on('click', async e=>{
			e.preventDefault();
			const selects = wrapper.find('select');
			let allValid = true;
			selects.each(function(){
				const value = $(this).val()?.trim();
				const text = $(this).find('option:selected').text();
				const isInvalid = !value || /choose/i.test(text);
				$(this).toggleClass('ti-invalid', isInvalid);
				if(isInvalid) allValid = false;
			});

			const oldErr = modal.find('.ti-error-msg');
			if (oldErr) oldErr.remove();

			if(!allValid){
				const err = $('<div class="ti-error-msg" style="color:#b81c23;margin-top:0.5rem;font-size:0.9rem;">Please complete all required selections.</div>');
				modal.find('h3').after(err);
				return;
			}
			
			await saveSelections(selects);
			setTimeout(closeModal, 150);
		});

		// Bind select events
		wrapper.find('#ti_market_'+groupKey).on('change', function(){ handleMarketChange(this); });


		// Initial summary
		updateSummary();
		
		const shippingRow = trigger.closest('tr.woocommerce-shipping-totals');
		if (shippingRow && document.body.classList.contains('woocommerce-checkout')) {
			const th = shippingRow.find('th');
			if (th && th.find('.turq_delivery_summary').length==0) {
				th.append(summary);
			}
			th.wrapInner('<div class="turq_delivery_wrapper"></div>');
		}		

	}

	// -------- Initial bind & fragment-safe rebind --------
	initAllTurqDelivery();
	$(document.body).on('updated_wc_div updated_checkout updated_shipping_method', initAllTurqDelivery);
	
	
	$(document).on('click', '.toggle-header .toggle-arrow', function (e) {
		e.preventDefault();

		this.blur();

		const $arrow  = $(this);
		const $header = $arrow.closest('.toggle-header');
		const $content = $header.next('.toggle-content');

		if (!$content.length) return;

		$content.stop(true, true).slideToggle(200);

		const isExpanded = $arrow.attr('aria-expanded') === 'true';
		$arrow.attr('aria-expanded', !isExpanded);

		$arrow.toggleClass('is-collapsed', isExpanded);
	});
			

    // Listen for WooCommerce AJAX add to cart success - this might come from an item in the cross sell
    $(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button){
		if ($button && $button.hasClass('ajax_add_to_cart') && $button.closest('#turq_cross_sell').length) $('body').trigger('update_checkout');
    });


	// Add item...
	
	const turqProductModal = $('#turq-product-modal');	
	let currentGroup = null;

	$(document).on('click', '.turq-add-item', function(e){
		e.preventDefault();
		e.stopPropagation();  

		const table = $(this).closest('.group-table');
		currentGroup = table.data('group');
		
		const groupLabel = $(this).data('group-label');

		$('#turq-product-add-to-package').prop('disabled', true);	
		$('#turq-product-modal .modal-content h3 span').text(groupLabel);
		turqProductModal.addClass('active');
		$('#main').addClass('turq_modal_active');	
		
		const select = $('#turq-product-search');
		select.val(null).trigger('change');
		$('#turq-qty').val(1);	
		
		select.selectWoo({
			placeholder: 'Search for a product',
			minimumInputLength: 3,

			ajax:{
				url: multiShippingVars.ajax_url,
				dataType: 'json',
				delay: 250,

				data: params => ({
					term: params.term,
					group: currentGroup,
					action: 'turq_search_products',
					security: multiShippingVars.nonce
				}),

				processResults: function(data) {
					$('#turq-product-add-to-package').prop('disabled', false);	
					return { results: data };
				}
			}
		});

		select.focus();

	});	
		
		
	$('#turq-product-add-to-package').on('click', function(e){
		e.preventDefault();
		e.stopPropagation();  
		$(this).prop('disabled', true);	

		const productID = $('#turq-product-search').val();
		const qty = $('#turq-qty').val() || 1;

		$.post(

			wc_add_to_cart_params.wc_ajax_url
				.replace('%%endpoint%%','add_to_cart'),

			{
				product_id: productID,
				quantity: qty,
				package_group: currentGroup
			},

			function(response){

				if(response.error){
					alert(response.error);
					return;
				}

				$(document.body).trigger(
					'added_to_cart',
					[response.fragments, response.cart_hash]
				);

				$(document.body).trigger('update_checkout');

				$('#main').removeClass('turq_modal_active');
				turqProductModal.removeClass('active');
				$(this).prop('disabled', false);	

			}
		);

	});	
		
	function turqProductModalClose() {
		$('#main').removeClass('turq_modal_active');
		turqProductModal.removeClass('active');
	}

	$(document).on('click', '.turq-product-close', function(e){
		e.preventDefault();	
		turqProductModalClose();
	});

	turqProductModal.on('click', e=>{if(e.target === turqProductModal[0]) turqProductModalClose();});				

	$(document).on('keydown', function(e){
		if(e.key === "Escape" && $('#turq-product-modal').is(':visible')) turqProductModalClose();
	});


});
