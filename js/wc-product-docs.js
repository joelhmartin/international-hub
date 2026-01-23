jQuery(function($){

  var $modal       = $('#doc-modal');
  var $modalForm   = $('#doc-modal-form');
  var $modalName   = $('#doc-modal-name');
  var $modalUrl    = $('#doc-modal-url');
  var $modalExpiry = $('#doc-modal-expiry');
  var $modalFile   = $modal.find('.doc-modal-file');
  var mediaFrame   = null;
  var activeTarget = null;
  var activeCtx    = null;

  function initDatePickers($ctx){
    var options = {
      dateFormat: 'mm/dd/yy',
      showButtonPanel: true,
      changeMonth: true,
      changeYear: true,
      prevText: '<',
      nextText: '>',
      closeText: 'Close',
      currentText: 'Today',
      beforeShow: function(input){
        $('.doc-date-picker').removeClass('datepicker-active');
        $(input).addClass('datepicker-active');
      },
      onClose: function(){
        $('.doc-date-picker').removeClass('datepicker-active');
      }
    };
    ($ctx || $(document)).find('.doc-date-picker').each(function(){
      var $input = $(this);
      if($input.data('datepicker-initialized')) return;
      $input.datepicker(options);
      $input.data('datepicker-initialized', true);
    }).on('click', function(){
      $(this).datepicker('show');
    });
  }

  initDatePickers($(document));

  function resetModal(){
    $modalName.val('');
    $modalUrl.val('');
    $modalExpiry.val('');
    $modalFile.text('No file selected');
  }

  function openModal(ctx, target){
    activeCtx    = ctx || 'product';
    activeTarget = target || null;
    if(!activeTarget){
      return;
    }
    resetModal();
    $modal.attr('aria-hidden', 'false').addClass('is-visible');
  }

  function closeModal(){
    $modal.attr('aria-hidden', 'true').removeClass('is-visible');
    activeCtx = null;
    activeTarget = null;
  }

  // Remove chip
  $(document).on('click', '.doc-chip .chip-remove', function(e){
    e.preventDefault();
    $(this).closest('.doc-chip').remove();
  });

  // Open modal
  $(document).on('click', '.open-doc-modal', function(e){
    e.preventDefault();
    var ctx    = $(this).data('context') || 'product';
    var target = $(this).data('target');
    openModal(ctx, target);
  });

  // Close modal interactions
  $(document).on('click', '.doc-modal__overlay, .doc-modal-cancel', function(e){
    e.preventDefault();
    closeModal();
  });

  $(document).on('keydown', function(e){
    if(e.key === 'Escape' && $modal.hasClass('is-visible')){
      closeModal();
    }
  });

  // Upload button inside modal
  $(document).on('click', '.doc-modal-upload-btn', function(e){
    e.preventDefault();
    if(!mediaFrame){
      mediaFrame = wp.media({
        title: 'Select or Upload Document',
        button: { text: 'Use this file' },
        multiple: false
      });

      mediaFrame.on('select', function(){
        var file = mediaFrame.state().get('selection').first().toJSON();
        $modalUrl.val(file.url);
        $modalFile.text(file.filename || file.url);
      });
    }

    mediaFrame.open();
  });

  function buildHiddenInput(nameAttr, value){
    return $('<input>', {
      type: 'hidden',
      name: nameAttr,
      value: value
    });
  }

  function appendChip(target, ctx, doc){
    var $list = $('.doc-list[data-product="'+target+'"]');
    if(!$list.length) return;

    var nameAttr, urlAttr, expiryAttr;
    if(ctx === 'centre'){
      nameAttr   = 'centre_doc_new_name[]';
      urlAttr    = 'centre_doc_new_url[]';
      expiryAttr = 'centre_doc_new_expiry[]';
    }else{
      nameAttr   = 'doc_new_name_'+target+'[]';
      urlAttr    = 'doc_new_url_'+target+'[]';
      expiryAttr = 'doc_new_expiry_'+target+'[]';
    }

    var $chip = $('<li class="doc-chip doc-chip-new"></li>');
    $('<span class="chip-label"></span>').text(doc.name).appendTo($chip);

    if(doc.url){
      var fileName = doc.url.split('/').pop();
      $('<a class="chip-link" target="_blank" rel="noopener noreferrer"></a>')
        .attr('href', doc.url)
        .text(fileName)
        .appendTo($chip);
    }

    $('<button type="button" class="chip-remove" title="Remove" aria-label="Remove">×</button>').appendTo($chip);
    $chip.append(buildHiddenInput(nameAttr, doc.name));
    $chip.append(buildHiddenInput(urlAttr, doc.url));

    var $expWrap = $('<div class="chip-expiry"></div>');
    var $label   = $('<label>Expires (MM/DD/YYYY)</label>');
    var $input   = $('<input type="text" class="doc-date-picker" placeholder="MM/DD/YYYY">').val(doc.expiry);
    $input.attr('name', expiryAttr);
    $label.append($input);
    $expWrap.append($label);
    $chip.append($expWrap);

    $list.append($chip);

    initDatePickers($chip);
  }

  $modalForm.on('submit', function(e){
    e.preventDefault();
    if(!activeTarget) return;

    var name   = $modalName.val().trim();
    var url    = $modalUrl.val().trim();
    var expiry = $modalExpiry.val().trim();

    if(!name || !url){
      window.alert('Please provide both a document name and file.');
      return;
    }

    appendChip(activeTarget, activeCtx, {
      name: name,
      url: url,
      expiry: expiry
    });

    closeModal();
  });

  $(document).on('click', '.ui-datepicker-current', function(e){
    e.preventDefault();
    var $active = $('.doc-date-picker.datepicker-active');
    if(!$active.length){
      $active = $('.doc-date-picker').filter(function(){
        return $(this).is(':focus');
      }).first();
    }
    if($active.length){
      $active.datepicker('setDate', new Date());
      $active.trigger('change');
    }
  });

});
