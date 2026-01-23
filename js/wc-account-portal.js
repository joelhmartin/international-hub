document.addEventListener('DOMContentLoaded', function(){
  var portal = document.querySelector('.wc-pd-portal');
  if(!portal) return;

  var navLinks   = portal.querySelectorAll('[data-portal-tab]');
  var sections   = portal.querySelectorAll('.wc-pd-portal__section');
  var folderBtns = portal.querySelectorAll('[data-folder-id]');
  var folderWraps= portal.querySelectorAll('.wc-pd-portal__folder');
  var searchWrap = portal.querySelector('.wc-pd-portal__search');
  var searchInput= searchWrap ? searchWrap.querySelector('input') : null;
  var emptyState = portal.querySelector('.wc-pd-portal__folder-empty');
  var refreshBtn = portal.querySelector('[data-portal-refresh]');
  var activeFolder = null;

  function activateSection(target){
    sections.forEach(function(section){
      var match = section.getAttribute('data-section') === target;
      section.classList.toggle('is-active', match);
    });
    navLinks.forEach(function(link){
      var match = link.getAttribute('data-portal-tab') === target;
      link.classList.toggle('is-active', match);
    });
  }

  navLinks.forEach(function(link){
    link.addEventListener('click', function(e){
      e.preventDefault();
      activateSection(this.getAttribute('data-portal-tab'));
    });
  });

  function showFolder(id){
    activeFolder = id;
    var hasFolder = !!id;

    folderBtns.forEach(function(btn){
      btn.classList.toggle('is-active', btn.getAttribute('data-folder-id') === id);
    });
    folderWraps.forEach(function(wrap){
      wrap.classList.toggle('is-active', wrap.getAttribute('data-folder-id') === id);
    });

    if(searchWrap){
      searchWrap.classList.toggle('has-folder', hasFolder);
    }
    if(emptyState){
      emptyState.style.display = hasFolder ? 'none' : '';
    }
    if(searchInput){
      searchInput.value = '';
    }
    filterDocs('');
  }

  function filterDocs(query){
    if(!activeFolder) return;
    var container = portal.querySelector('.wc-pd-portal__folder.is-active');
    if(!container) return;
    var docs = container.querySelectorAll('.wc-pd-portal-doc');
    docs.forEach(function(doc){
      var text = (doc.getAttribute('data-search') || '').toLowerCase();
      var match = !query || text.indexOf(query) !== -1;
      doc.style.display = match ? '' : 'none';
    });
  }

  folderBtns.forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      showFolder(this.getAttribute('data-folder-id'));
    });
  });

  if(searchInput){
    searchInput.addEventListener('input', function(e){
      filterDocs(e.target.value.toLowerCase());
    });
  }

  if(refreshBtn){
    refreshBtn.addEventListener('click', function(){
      window.location.reload();
    });
  }

  // Leave folders closed initially; search appears once a folder is opened.
});
