(function () {
  'use strict';

  var form = document.getElementById('articleForm');
  var csrfInput = form ? form.querySelector('input[name="csrf_token"]') : null;
  var csrfToken = csrfInput ? csrfInput.value : '';
  var progress = document.getElementById('uploadProgress');
  var progressBar = document.getElementById('uploadProgressBar');

  function setProgress(percent) {
    if (!progress || !progressBar) return;
    progress.classList.remove('d-none');
    var text = String(percent) + '%';
    progressBar.style.width = text;
    progressBar.textContent = text;
    if (percent >= 100) {
      window.setTimeout(function () { progress.classList.add('d-none'); }, 900);
    }
  }

  function escapeHtml(text) {
    return String(text).replace(/[<>&"']/g, function (ch) {
      return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#039;' })[ch];
    });
  }

  function jsonFetch(url, formData) {
    return fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      return response.text().then(function (text) {
        var data = {};
        if (text) {
          try { data = JSON.parse(text); } catch (e) {
            throw new Error(response.ok ? '上传接口返回异常，请检查 PHP 错误日志。' : '上传接口请求失败（HTTP ' + response.status + '）。');
          }
        }
        if (!response.ok || data.ok === false || data.errno === 1) {
          throw new Error(data.message || '操作失败，请稍后重试。');
        }
        return data;
      });
    });
  }


  // 字体图标开关：保留真实 checkbox 提交，界面用 bi-toggle-on/off 表示状态
  function refreshVisualToggle(input) {
    if (!input) return;
    var label = document.querySelector('label[for="' + input.id + '"]');
    if (!label || !label.classList.contains('visual-toggle-button')) return;
    var icon = label.querySelector('.visual-toggle-icon');
    label.classList.toggle('is-on', input.checked);
    label.classList.toggle('is-off', !input.checked);
    if (icon) {
      icon.classList.toggle('bi-toggle-on', input.checked);
      icon.classList.toggle('bi-toggle-off', !input.checked);
    }
  }
  document.querySelectorAll('.visual-toggle-input').forEach(function (input) {
    refreshVisualToggle(input);
    input.addEventListener('change', function () { refreshVisualToggle(input); });
  });

  // 文章类型切换：轮播图类型 / 普通类型
  var typeRadios = document.querySelectorAll('input[name="article_type"]');
  var carouselBlocks = document.querySelectorAll('[data-type-visible="carousel"]');
  function activeArticleType() {
    var checked = document.querySelector('input[name="article_type"]:checked');
    return checked ? checked.value : '1';
  }
  function refreshArticleType() {
    var type = activeArticleType();
    document.querySelectorAll('.article-type-option').forEach(function (label) {
      var input = label.querySelector('input[type="radio"]');
      label.classList.toggle('is-active', !!input && input.checked);
    });
    carouselBlocks.forEach(function (block) {
      block.classList.toggle('d-none', type !== '1');
    });
  }
  typeRadios.forEach(function (radio) { radio.addEventListener('change', refreshArticleType); });
  refreshArticleType();

  // 封面裁剪与上传
  var coverBox = document.getElementById('coverUploadBox');
  var coverInput = document.getElementById('coverFileInput');
  var coverPreview = document.getElementById('coverPreview');
  var coverPlaceholder = document.getElementById('coverPlaceholder');
  var coverIdInput = document.getElementById('cover_image_id');
  var cropModal = document.getElementById('coverCropModal');
  var cropImage = document.getElementById('coverCropImage');
  var confirmCoverCrop = document.getElementById('confirmCoverCrop');
  var cropper = null;
  var cropObjectUrl = '';

  function closeCropModal() {
    if (!cropModal) return;
    cropModal.classList.remove('is-open');
    cropModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    if (cropper) { cropper.destroy(); cropper = null; }
    if (cropObjectUrl) { URL.revokeObjectURL(cropObjectUrl); cropObjectUrl = ''; }
    if (coverInput) coverInput.value = '';
  }

  function openCropModal(file) {
    if (!cropModal || !cropImage) return;
    if (!window.Cropper) {
      window.alert('裁剪组件加载失败，请检查 jsDelivr 是否能正常访问。');
      return;
    }
    if (file.size > 20 * 1024 * 1024) {
      window.alert('封面图片不能超过 20MB。');
      return;
    }
    if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
      window.alert('只允许上传 jpg、png、webp 图片。');
      return;
    }
    cropObjectUrl = URL.createObjectURL(file);
    cropImage.src = cropObjectUrl;
    cropModal.classList.add('is-open');
    cropModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    window.setTimeout(function () {
      cropper = new window.Cropper(cropImage, {
        aspectRatio: 1,
        viewMode: 1,
        autoCropArea: 0.92,
        background: false,
        responsive: true,
        dragMode: 'move'
      });
    }, 60);
  }

  if (coverBox && coverInput) {
    coverBox.addEventListener('click', function () { coverInput.click(); });
    coverInput.addEventListener('change', function () {
      var file = coverInput.files && coverInput.files[0] ? coverInput.files[0] : null;
      if (file) openCropModal(file);
    });
  }
  document.querySelectorAll('[data-close-crop-modal]').forEach(function (btn) {
    btn.addEventListener('click', closeCropModal);
  });
  if (confirmCoverCrop) {
    confirmCoverCrop.addEventListener('click', function () {
      if (!cropper) return;
      confirmCoverCrop.disabled = true;
      confirmCoverCrop.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>上传中...';
      var canvas = cropper.getCroppedCanvas({ width: 800, height: 800, imageSmoothingEnabled: true, imageSmoothingQuality: 'high' });
      if (!canvas) {
        confirmCoverCrop.disabled = false;
        confirmCoverCrop.innerHTML = '<i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>裁剪并上传';
        window.alert('裁剪失败，请重新选择图片。');
        return;
      }
      canvas.toBlob(function (blob) {
        if (!blob) {
          confirmCoverCrop.disabled = false;
          confirmCoverCrop.innerHTML = '<i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>裁剪并上传';
          window.alert('裁剪失败，请重新选择图片。');
          return;
        }
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('cover_image', blob, 'cover-800.jpg');
        setProgress(10);
        jsonFetch('upload_article_cover.php', fd).then(function (data) {
          setProgress(100);
          if (coverIdInput) coverIdInput.value = String(data.id || '');
          if (coverPreview) {
            coverPreview.src = data.url;
            coverPreview.classList.remove('d-none');
          }
          if (coverPlaceholder) coverPlaceholder.classList.add('d-none');
          if (coverBox) coverBox.classList.add('has-image');
          closeCropModal();
        }).catch(function (err) {
          window.alert(err.message || '封面上传失败。');
        }).finally(function () {
          confirmCoverCrop.disabled = false;
          confirmCoverCrop.innerHTML = '<i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>裁剪并上传';
        });
      }, 'image/jpeg', 0.9);
    });
  }

  // 主图上传与删除
  var mainAdd = document.getElementById('mainImageAddButton');
  var mainInput = document.getElementById('mainImageInput');
  var mainGrid = document.getElementById('mainImageGrid');
  var mainIdsInput = document.getElementById('main_image_ids');

  function currentMainIds() {
    if (!mainIdsInput || mainIdsInput.value.trim() === '') return [];
    return mainIdsInput.value.split(',').map(function (v) { return parseInt(v, 10); }).filter(function (n) { return n > 0; });
  }
  function setMainIds(ids) {
    if (mainIdsInput) mainIdsInput.value = ids.filter(function (n) { return n > 0; }).join(',');
  }
  function appendMainImage(id, url) {
    if (!mainGrid) return;
    var item = document.createElement('div');
    item.className = 'main-image-thumb';
    item.setAttribute('data-image-id', String(id));
    item.innerHTML = '<img src="' + escapeHtml(url) + '" alt="主图"><button type="button" class="main-image-delete" data-delete-image title="删除主图" aria-label="删除主图"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
    mainGrid.appendChild(item);
    var ids = currentMainIds();
    if (ids.indexOf(id) === -1) ids.push(id);
    setMainIds(ids);
  }
  function removeMainImage(id, shouldRequest) {
    var ids = currentMainIds().filter(function (item) { return item !== id; });
    setMainIds(ids);
    var item = mainGrid ? mainGrid.querySelector('[data-image-id="' + id + '"]') : null;
    if (item) item.remove();
    if (!shouldRequest) return;
    var fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('image_id', String(id));
    jsonFetch('delete_article_image.php', fd).catch(function (err) {
      window.alert(err.message || '图片删除失败。');
    });
  }
  if (mainAdd && mainInput) {
    mainAdd.addEventListener('click', function () { mainInput.click(); });
    mainInput.addEventListener('change', function () {
      var files = Array.prototype.slice.call(mainInput.files || []);
      var remaining = 20 - currentMainIds().length;
      if (files.length > remaining) {
        window.alert('主图最多 20 张，本次最多还能上传 ' + remaining + ' 张。');
        files = files.slice(0, Math.max(remaining, 0));
      }
      if (files.length === 0) { mainInput.value = ''; return; }
      var chain = Promise.resolve();
      files.forEach(function (file) {
        chain = chain.then(function () {
          if (file.size > 20 * 1024 * 1024) throw new Error('单张主图不能超过 20MB。');
          if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) throw new Error('只允许上传 jpg、png、webp 图片。');
          var fd = new FormData();
          fd.append('csrf_token', csrfToken);
          fd.append('image', file);
          setProgress(15);
          return jsonFetch('upload_article_main_image.php', fd).then(function (data) {
            appendMainImage(Number(data.id), data.url);
            setProgress(100);
          });
        });
      });
      chain.catch(function (err) { window.alert(err.message || '主图上传失败。'); }).finally(function () { mainInput.value = ''; });
    });
  }
  if (mainGrid) {
    mainGrid.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-delete-image]');
      if (!btn) return;
      var item = btn.closest('[data-image-id]');
      var id = item ? parseInt(item.getAttribute('data-image-id') || '0', 10) : 0;
      if (id > 0 && window.confirm('确定删除这张主图吗？')) removeMainImage(id, true);
    });
  }

  // wangEditor v5
  var editorBox = document.getElementById('contentEditor');
  var hidden = document.getElementById('content_html');
  if (editorBox && hidden) {
    var editor = null;
    var initialHtml = editorBox.getAttribute('data-initial-html') || hidden.value || '<p><br></p>';

    if (!window.wangEditor || typeof window.wangEditor.createEditor !== 'function' || typeof window.wangEditor.createToolbar !== 'function') {
      editorBox.innerHTML = '<div class="alert alert-warning m-0">wangEditor 官方资源加载失败。请检查服务器是否能访问 jsDelivr，或把 @wangeditor/editor 的 dist 文件下载到本地后改成本地路径。</div>';
      hidden.classList.remove('d-none');
      hidden.classList.add('form-control');
      hidden.rows = 12;
      if (form) form.addEventListener('submit', function () { hidden.value = hidden.value || ''; });
    } else {
      var editorConfig = {
        placeholder: '请输入文章正文，可插入图片、标题、列表、引用等内容。',
        scroll: true,
        onChange: function (ed) { hidden.value = ed.getHtml(); },
        MENU_CONF: {}
      };
      editorConfig.MENU_CONF.uploadImage = {
        server: editorBox.getAttribute('data-upload-url') || 'upload_article_image.php',
        fieldName: 'image',
        maxFileSize: 20 * 1024 * 1024,
        maxNumberOfFiles: 20,
        allowedFileTypes: ['image/jpeg', 'image/png', 'image/webp'],
        meta: { csrf_token: editorBox.getAttribute('data-csrf-token') || '' },
        timeout: 60000,
        onProgress: function (progressPercent) { setProgress(Math.max(1, Math.min(99, Math.round(progressPercent || 1)))); },
        customInsert: function (res, insertFn) {
          setProgress(100);
          if (res && res.errno === 0 && res.data && res.data.url) {
            insertFn(res.data.url, res.data.alt || '', res.data.href || '');
            return;
          }
          window.alert((res && res.message) ? res.message : '上传失败');
        },
        onFailed: function (file, res) { window.alert((res && res.message) ? res.message : '上传失败'); },
        onError: function () { window.alert('上传失败，请检查网络或图片大小。'); }
      };
      editor = window.wangEditor.createEditor({ selector: '#contentEditor', html: initialHtml, config: editorConfig, mode: 'default' });
      window.wangEditor.createToolbar({
        editor: editor,
        selector: '#editorToolbar',
        config: { excludeKeys: ['fullScreen', 'group-video', 'insertVideo', 'uploadVideo', 'todo', 'insertTable', 'codeBlock'] },
        mode: 'default'
      });
      hidden.value = editor.getHtml();
      if (form) form.addEventListener('submit', function () { hidden.value = editor && typeof editor.getHtml === 'function' ? editor.getHtml() : hidden.value; });
    }
  }

  // 表单提交前基础体验校验，最终安全校验仍以后端为准
  if (form) {
    form.addEventListener('submit', function (event) {
      var coverId = coverIdInput ? parseInt(coverIdInput.value || '0', 10) : 0;
      if (!coverId) {
        event.preventDefault();
        window.alert('请先上传并裁剪封面图。');
        return;
      }
      if (activeArticleType() === '1' && currentMainIds().length < 1) {
        event.preventDefault();
        window.alert('轮播图类型至少需要上传 1 张主图。');
      }
    });
  }

  // 百度地图弹窗选点
  var locationCard = document.querySelector('.location-picker-card');
  var mapBox = document.getElementById('baiduMapBox');
  var modal = document.getElementById('locationMapModal');
  var openLocationButton = document.getElementById('location_address_display');
  var closeModalButtons = document.querySelectorAll('[data-close-map-modal]');
  var confirmMapButton = document.getElementById('confirmMapLocation');
  var clearLocationButton = document.getElementById('clearLocationButton');
  var lngInput = document.getElementById('location_lng');
  var latInput = document.getElementById('location_lat');
  var addressInput = document.getElementById('location_address');
  var addressText = document.getElementById('locationAddressText');
  var selectedAddress = document.getElementById('mapSelectedAddress');
  var selectedCoordinates = document.getElementById('mapSelectedCoordinates');
  var browserBtn = document.getElementById('useBrowserLocation');
  var map = null;
  var marker = null;
  var geocoder = null;
  var mapInitTimer = null;
  var mapLoaderPoll = null;
  var lastMapScriptUrl = '';
  var loadingBaiduScript = false;
  var baiduScriptRequested = false;
  var baiduMapInitialized = false;
  var defaultAddressText = '点击选择当前位置或手动在地图上选点';

  function currentOriginText() {
    try { return window.location.protocol + '//' + window.location.host; } catch (e) { return ''; }
  }
  function setPlaceholder(message, extra) {
    if (!mapBox) return;
    var html = '<div class="map-placeholder">' + escapeHtml(message);
    if (extra) html += '<div class="map-debug small mt-2 text-muted">' + escapeHtml(extra) + '</div>';
    html += '</div>';
    mapBox.innerHTML = html;
  }
  function setMapHint(message) {
    var hint = document.getElementById('baiduMapHint');
    if (hint) hint.textContent = message;
  }
  function formatCoord(value) {
    var num = Number(value);
    return isFinite(num) ? num.toFixed(7) : '';
  }
  function refreshVisibleLocation() {
    var address = addressInput && addressInput.value ? addressInput.value : '';
    var lng = lngInput && lngInput.value ? lngInput.value : '';
    var lat = latInput && latInput.value ? latInput.value : '';
    if (addressText) addressText.textContent = address || defaultAddressText;
    if (selectedAddress) selectedAddress.textContent = address || '尚未选择地址';
    if (selectedCoordinates) selectedCoordinates.textContent = lng && lat ? lng + ', ' + lat : '未选择坐标';
  }
  function updateLocationDisplay(lng, lat, address) {
    var lngText = formatCoord(lng);
    var latText = formatCoord(lat);
    if (!lngText || !latText) return;
    if (lngInput) lngInput.value = lngText;
    if (latInput) latInput.value = latText;
    if (typeof address === 'string' && address !== '' && addressInput) addressInput.value = address;
    refreshVisibleLocation();
  }
  function clearLocation() {
    if (lngInput) lngInput.value = '';
    if (latInput) latInput.value = '';
    if (addressInput) addressInput.value = '';
    refreshVisibleLocation();
    if (marker && map && typeof map.removeOverlay === 'function') { map.removeOverlay(marker); marker = null; }
    setMapHint('已清除位置；可以重新定位或在地图上点击选择。');
  }
  function getBaiduApi() {
    if (window.BMap && typeof window.BMap.Map === 'function') return window.BMap;
    if (window.BMapGL && typeof window.BMapGL.Map === 'function') return window.BMapGL;
    return null;
  }
  function getPointFromEvent(event) { return event ? (event.point || event.latlng || event.latLng || null) : null; }
  function getPointLng(point) { return Number(point && point.lng); }
  function getPointLat(point) { return Number(point && point.lat); }
  function reverseGeocode(point) {
    if (!geocoder || typeof geocoder.getLocation !== 'function') return;
    geocoder.getLocation(point, function (rs) {
      var address = '';
      if (rs) {
        address = rs.address || (rs.content && rs.content.address) || '';
        if (!address && rs.addressComponents) {
          var c = rs.addressComponents;
          address = [c.province, c.city, c.district, c.street, c.streetNumber].filter(Boolean).join('');
        }
      }
      updateLocationDisplay(getPointLng(point), getPointLat(point), address);
    });
  }
  function applyPoint(point, shouldCenter) {
    var BMapApi = getBaiduApi();
    if (!point || !map || !BMapApi) return;
    if (marker && typeof map.removeOverlay === 'function') map.removeOverlay(marker);
    marker = new BMapApi.Marker(point);
    if (typeof marker.enableDragging === 'function') marker.enableDragging();
    map.addOverlay(marker);
    if (shouldCenter && typeof map.centerAndZoom === 'function') map.centerAndZoom(point, 16);
    updateLocationDisplay(getPointLng(point), getPointLat(point), '');
    setMapHint('已选择位置；可继续点击地图或拖动标记点微调。');
    reverseGeocode(point);
    if (typeof marker.addEventListener === 'function') {
      marker.addEventListener('dragend', function (e) {
        var dragPoint = getPointFromEvent(e);
        if (!dragPoint && marker && typeof marker.getPosition === 'function') dragPoint = marker.getPosition();
        applyPoint(dragPoint, false);
      });
    }
  }
  function locateWithBaidu() {
    var BMapApi = getBaiduApi();
    if (!map || !BMapApi || !BMapApi.Geolocation) { setMapHint('地图定位组件不可用，可直接点击地图选择位置。'); return; }
    setMapHint('正在尝试默认定位当前位置；如果失败，可直接点击地图选择。');
    try {
      var geolocation = new BMapApi.Geolocation();
      if (typeof geolocation.enableSDKLocation === 'function') geolocation.enableSDKLocation();
      geolocation.getCurrentPosition(function (result) {
        var point = result && (result.point || result.latlng || result.latLng);
        if (point) applyPoint(point, true); else setMapHint('定位失败，可直接点击地图选择位置。');
      });
    } catch (e) { setMapHint('定位失败，可直接点击地图选择位置。'); }
  }
  function initMap() {
    var BMapApi = getBaiduApi();
    if (!mapBox || !BMapApi || baiduMapInitialized) return;
    baiduMapInitialized = true;
    mapBox.innerHTML = '';
    try {
      map = new BMapApi.Map('baiduMapBox');
      geocoder = new BMapApi.Geocoder();
      var lng = lngInput && lngInput.value ? Number(lngInput.value) : 116.404;
      var lat = latInput && latInput.value ? Number(latInput.value) : 39.915;
      var point = new BMapApi.Point(lng, lat);
      map.centerAndZoom(point, (lngInput && lngInput.value) ? 16 : 12);
      if (typeof map.enableScrollWheelZoom === 'function') map.enableScrollWheelZoom(true);
      if (typeof map.addEventListener === 'function') {
        map.addEventListener('click', function (event) { applyPoint(getPointFromEvent(event), false); });
      }
      if (lngInput && lngInput.value && latInput && latInput.value) applyPoint(point, false); else locateWithBaidu();
    } catch (e) {
      setPlaceholder('百度地图初始化失败。', '当前域名：' + currentOriginText() + '；脚本：' + lastMapScriptUrl);
      setMapHint('地图初始化失败，请检查 AK、白名单或控制台报错。');
    }
  }
  window.__initSecureBlogBaiduMap = function () { initMap(); };
  function loadBaiduScript() {
    if (!locationCard || !mapBox) return;
    var ak = (locationCard.getAttribute('data-baidu-ak') || '').trim();
    if (!ak) { setPlaceholder('未配置百度地图 AK，请先到“系统设置”填写百度地图浏览器端 AK。'); return; }
    if (getBaiduApi()) { initMap(); return; }
    if (loadingBaiduScript || baiduScriptRequested) return;
    loadingBaiduScript = true;
    baiduScriptRequested = true;
    setPlaceholder('百度地图加载中...');
    var protocol = window.location.protocol === 'http:' ? 'http:' : 'https:';
    lastMapScriptUrl = protocol + '//api.map.baidu.com/api?v=3.0&type=webgl&ak=' + encodeURIComponent(ak) + '&callback=__initSecureBlogBaiduMap';
    var script = document.createElement('script');
    script.src = lastMapScriptUrl;
    script.async = true;
    script.onerror = function () {
      loadingBaiduScript = false;
      setPlaceholder('百度地图脚本加载失败。', '当前域名：' + currentOriginText() + '；请检查 AK、Referer 白名单和浏览器控制台。');
      setMapHint('地图脚本加载失败。');
    };
    document.head.appendChild(script);
    mapInitTimer = window.setTimeout(function () {
      if (!getBaiduApi()) {
        setPlaceholder('百度地图加载超时。', '当前域名：' + currentOriginText() + '；脚本：' + lastMapScriptUrl);
        setMapHint('地图加载超时，请检查 AK 是否正确、Referer 白名单是否放行当前域名。');
      }
    }, 10000);
    mapLoaderPoll = window.setInterval(function () {
      if (getBaiduApi()) {
        window.clearInterval(mapLoaderPoll);
        window.clearTimeout(mapInitTimer);
        initMap();
      }
    }, 300);
  }
  function openMapModal() {
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    refreshVisibleLocation();
    window.setTimeout(loadBaiduScript, 60);
  }
  function closeMapModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
  }
  refreshVisibleLocation();
  if (openLocationButton) openLocationButton.addEventListener('click', openMapModal);
  closeModalButtons.forEach(function (btn) { btn.addEventListener('click', closeMapModal); });
  if (confirmMapButton) confirmMapButton.addEventListener('click', closeMapModal);
  if (clearLocationButton) clearLocationButton.addEventListener('click', clearLocation);
  if (browserBtn) browserBtn.addEventListener('click', locateWithBaidu);
})();
