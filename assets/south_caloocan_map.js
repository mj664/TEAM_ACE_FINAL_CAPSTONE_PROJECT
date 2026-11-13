/**
 * Interactive Leaflet inventory map for South Caloocan.
 * Expects window.SOUTH_CALOOCAN_MAP = { barangays, districts, center, zoom }
 */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildBarangayCoords() {
    var southCaloocanCoords = {
      // Grace Park West (1st Ave to 12th Ave)
      38: [14.6512, 120.9782], 39: [14.6508, 120.9795], 40: [14.6502, 120.9808],
      41: [14.6496, 120.9820], 42: [14.6491, 120.9832], 43: [14.6485, 120.9845],
      44: [14.6480, 120.9858], 45: [14.6525, 120.9785], 46: [14.6520, 120.9798],
      47: [14.6515, 120.9810], 48: [14.6510, 120.9822], 49: [14.6505, 120.9835],
      50: [14.6499, 120.9848], 51: [14.6494, 120.9860], 52: [14.6538, 120.9788],
      53: [14.6532, 120.9800], 54: [14.6528, 120.9812], 55: [14.6522, 120.9825],
      56: [14.6517, 120.9838], 57: [14.6512, 120.9850], 58: [14.6506, 120.9862],
      59: [14.6550, 120.9790], 60: [14.6545, 120.9803], 61: [14.6540, 120.9815],
      62: [14.6535, 120.9828], 63: [14.6530, 120.9840], 64: [14.6525, 120.9852],
      65: [14.6520, 120.9865], 66: [14.6562, 120.9792], 67: [14.6558, 120.9805],
      68: [14.6552, 120.9818], 69: [14.6547, 120.9830], 70: [14.6542, 120.9842],
      71: [14.6537, 120.9855], 72: [14.6531, 120.9868],
      73: [14.6568, 120.9828], 74: [14.6564, 120.9842], 75: [14.6560, 120.9856], 76: [14.6555, 120.9870],

      // Grace Park East (86 to 125)
      86: [14.6500, 120.9880], 87: [14.6505, 120.9892], 88: [14.6510, 120.9905],
      89: [14.6515, 120.9918], 90: [14.6520, 120.9930], 91: [14.6525, 120.9942],
      92: [14.6530, 120.9955], 93: [14.6488, 120.9882], 94: [14.6492, 120.9895],
      96: [14.6498, 120.9910], 97: [14.6503, 120.9922], 98: [14.6508, 120.9935],
      100: [14.6513, 120.9948], 101: [14.6518, 120.9960], 102: [14.6475, 120.9885],
      103: [14.6480, 120.9898], 104: [14.6485, 120.9912], 105: [14.6490, 120.9925],
      106: [14.6495, 120.9938], 107: [14.6500, 120.9950], 108: [14.6505, 120.9962],
      109: [14.6462, 120.9888], 110: [14.6468, 120.9900], 111: [14.6472, 120.9915],
      112: [14.6478, 120.9928], 113: [14.6482, 120.9940], 114: [14.6488, 120.9952],
      115: [14.6492, 120.9965], 116: [14.6450, 120.9890], 117: [14.6455, 120.9902],
      118: [14.6460, 120.9918], 119: [14.6465, 120.9930], 120: [14.6470, 120.9942],
      121: [14.6475, 120.9955], 122: [14.6480, 120.9968], 123: [14.6438, 120.9892],
      124: [14.6442, 120.9905], 125: [14.6448, 120.9920],

      // Morning Breeze & University Hills
      77: [14.6575, 120.9740], 78: [14.6580, 120.9752], 79: [14.6565, 120.9742],
      80: [14.6570, 120.9755], 81: [14.6610, 120.9810], 82: [14.6612, 120.9822],
      83: [14.6615, 120.9835], 84: [14.6598, 120.9812], 85: [14.6600, 120.9825],

      // Dagat-Dagatan / Sangandaan Area
      1: [14.6548, 120.9700], 2: [14.6542, 120.9714], 3: [14.6536, 120.9728],
      4: [14.6556, 120.9702], 5: [14.6550, 120.9716], 6: [14.6562, 120.9706],
      7: [14.6568, 120.9720],
      8: [14.6560, 120.9650], 9: [14.6570, 120.9665], 12: [14.6520, 120.9680],
      14: [14.6505, 120.9670], 28: [14.6540, 120.9690],

      // Poblacion
      10: [14.6522, 120.9736], 11: [14.6516, 120.9748], 13: [14.6510, 120.9732],
      15: [14.6504, 120.9744], 16: [14.6498, 120.9756], 17: [14.6528, 120.9758],
      18: [14.6522, 120.9770], 19: [14.6514, 120.9762],

      // Kaunlaran Village
      20: [14.6546, 120.9774], 21: [14.6540, 120.9786], 22: [14.6534, 120.9798],
      23: [14.6552, 120.9780], 24: [14.6548, 120.9794],

      // Maypajo
      25: [14.6468, 120.9752], 26: [14.6462, 120.9766], 27: [14.6456, 120.9780],
      29: [14.6450, 120.9794], 30: [14.6444, 120.9808], 31: [14.6474, 120.9760],
      32: [14.6468, 120.9774], 33: [14.6462, 120.9788], 34: [14.6456, 120.9802],
      35: [14.6450, 120.9816],

      // Marulas
      36: [14.6426, 120.9824], 37: [14.6422, 120.9838],

      // Balintawak
      95: [14.6578, 121.0028], 99: [14.6568, 121.0042],

      // Barrio San Jose
      126: [14.6448, 120.9968], 127: [14.6442, 120.9982], 128: [14.6436, 120.9996],
      129: [14.6430, 121.0010], 130: [14.6454, 120.9980], 131: [14.6448, 120.9994],

      // Bagong Barrio West
      132: [14.6588, 121.0040], 133: [14.6582, 121.0054], 134: [14.6576, 121.0068],
      135: [14.6570, 121.0082], 136: [14.6564, 121.0096], 137: [14.6600, 121.0044],
      138: [14.6594, 121.0058], 139: [14.6588, 121.0072], 140: [14.6582, 121.0086],
      141: [14.6576, 121.0100], 142: [14.6612, 121.0048], 143: [14.6606, 121.0062],
      144: [14.6600, 121.0076], 145: [14.6594, 121.0090], 146: [14.6588, 121.0104],
      147: [14.6624, 121.0052], 148: [14.6618, 121.0066], 149: [14.6612, 121.0080],
      150: [14.6606, 121.0094], 151: [14.6600, 121.0108], 152: [14.6636, 121.0056],
      153: [14.6630, 121.0070], 154: [14.6624, 121.0084], 155: [14.6618, 121.0098],

      // Bagong Barrio East
      156: [14.6596, 121.0138], 157: [14.6588, 121.0154],

      // Libis Baesa / Reparo
      158: [14.6662, 121.0092], 159: [14.6654, 121.0108],
      160: [14.6646, 121.0124], 161: [14.6638, 121.0140],

      // Santa Quiteria & Talipapa
      162: [14.6780, 121.0110], 163: [14.6765, 121.0150], 164: [14.6810, 121.0180]
    };

    return southCaloocanCoords;
  }

  ready(function () {
    var data = window.SOUTH_CALOOCAN_MAP;
    var mapEl = document.getElementById('map');
    var mapPane = document.getElementById('scMapPane');
    var listPane = document.getElementById('scListPane');
    if (!data || !mapEl || typeof L === 'undefined') return;

    var byNumber = {};
    (data.barangays || []).forEach(function (b) {
      byNumber[b.number] = b;
    });

    var districts = data.districts || {};
    var districtNames = Object.keys(districts);
    var southCaloocanCoords = buildBarangayCoords();
    var leafletMap = null;
    var markerGroup = null;
    var geojsonLayer = null;
    var boundaryFeatures = [];
    var markersByNumber = {};
    var gpsMarker = null;
    var gpsWatchId = null;
    var didCenterOnGps = false;

    function statusClass(brgy) {
      if (!brgy) return 'idle';
      if (brgy.no_supplies) return 'idle'; // report sent, but no medical quantities filled in
      if (brgy.level === 'critical' || brgy.low_stock) return 'critical';
      if (brgy.level === 'warning') return 'warning';
      if (brgy.level === 'optimal') return 'optimal';
      if (brgy.has_report && brgy.level === 'idle') return 'idle'; // incomplete report: can't be rated "All good"
      if (brgy.has_report && !brgy.level) return 'optimal';        // older data without a level
      return 'idle';
    }

    function districtFor(number) {
      var found = '';
      districtNames.forEach(function (name) {
        var list = (districts[name] && districts[name].barangays) || [];
        if (list.indexOf(Number(number)) !== -1 || list.indexOf(String(number)) !== -1) {
          found = name;
        }
      });
      return found;
    }

    function supplyValue(brgy, key) {
      if (!brgy || !brgy.has_report || !brgy.supplies || brgy.supplies[key] == null) return '—';
      return brgy.supplies[key];
    }

    function statusText(brgy) {
      var level = statusClass(brgy);
      if (level === 'critical') return brgy.out_of_stock ? 'Out of stock' : 'Low stock';
      if (level === 'warning') return 'Warning';
      if (level === 'optimal') return 'All good';
      if (brgy && brgy.has_report && brgy.no_supplies) return 'No supplies reported';
      if (brgy && brgy.has_report && brgy.incomplete) return 'Incomplete report';
      return 'No report submitted';
    }

    function statusColor(brgy) {
      var level = statusClass(brgy);
      if (level === 'critical') return '#dc2626';
      if (level === 'warning') return '#d97706';
      if (level === 'optimal') return '#059669';
      return '#6b7280';
    }

    function featureBarangayNumber(feature) {
      var properties = feature && feature.properties ? feature.properties : {};
      var rawName = properties.NAME_3 || properties.VARNAME_3 || '';
      var brgyNum = String(rawName).replace(/[^0-9]/g, '');
      return brgyNum === '' ? null : parseInt(brgyNum, 10);
    }

    function getStatus(number) {
      return statusClass(byNumber[number]);
    }

    function addFallbackMarkers() {
      for (var n = 1; n <= 164; n++) {
        if (markersByNumber[n] || !southCaloocanCoords[n]) continue;
        var brgy = byNumber[n] || {
          number: n,
          district: districtFor(n),
          level: 'idle',
          has_report: false,
          supplies: {}
        };
        var marker = L.marker(southCaloocanCoords[n], {
          icon: numberedIcon(brgy, n),
          keyboard: true
        }).bindPopup(popupContent(brgy, n), {
          maxWidth: 280,
          closeButton: true,
          autoPan: true
        });
        markerGroup.addLayer(marker);
        markersByNumber[n] = marker;
      }
    }

    function addMarker(number, latlng) {
      var brgy = byNumber[number] || {
        number: number,
        district: districtFor(number),
        level: 'idle',
        has_report: false,
        supplies: {}
      };
      var marker = L.marker(latlng, {
        icon: numberedIcon(brgy, number),
        keyboard: true,
        riseOnHover: true
      }).bindPopup(popupContent(brgy, number), {
        maxWidth: 300,
        closeButton: true,
        autoPan: true
      });
      markerGroup.addLayer(marker);
      markersByNumber[number] = marker;
    }

    // Real barangay boundaries (assets/caloocan_barangays.json, built from GADM). Each outline is tinted
    // with its status color and the numbered circle sits at the barangay's visual centre.
    function loadBoundaryMarkers() {
      if (!data.geojson) return Promise.reject(new Error('No boundary file configured'));
      return fetch(data.geojson, { headers: { Accept: 'application/json' } })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (geo) {
          var features = (geo && geo.features) || [];
          if (!features.length) throw new Error('Boundary file is empty');
          boundaryFeatures = features;
          geojsonLayer = L.geoJSON(geo, {
            interactive: false,
            style: function (feature) {
              var color = statusColor(byNumber[feature.properties.number]);
              return { color: color, weight: 1, opacity: 0.55, fillColor: color, fillOpacity: 0.08 };
            }
          }).addTo(leafletMap);
          features.forEach(function (feature) {
            var p = feature.properties || {};
            if (!p.number || markersByNumber[p.number] || p.label_lat == null) return;
            addMarker(p.number, [p.label_lat, p.label_lng]);
          });
          addFallbackMarkers();
          if (!gpsFollow) leafletMap.fitBounds(geojsonLayer.getBounds().pad(0.03));
        });
    }

    function loadNumberedBarangayMarkers() {
      var query = '[out:json][timeout:25];area["name"="Caloocan"]["boundary"="administrative"]->.a;relation["boundary"="administrative"]["admin_level"="10"](area.a);out tags center;';
      var url = 'https://overpass-api.de/api/interpreter?data=' + encodeURIComponent(query);
      return fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (data) {
          var count = 0;
          (data.elements || []).forEach(function (element) {
            var tags = element.tags || {};
            var refMatch = String(tags.ref || '').match(/137501(\d{3})$/);
            var nameMatch = String(tags.name || '').match(/barangay\s*(\d+)/i);
            var number = refMatch ? parseInt(refMatch[1], 10) : (nameMatch ? parseInt(nameMatch[1], 10) : 0);
            var center = element.center;
            if (!number || number > 164 || !center || markersByNumber[number]) return;
            var brgy = byNumber[number] || {
              number: number,
              district: districtFor(number),
              level: 'idle',
              has_report: false,
              supplies: {}
            };
            var marker = L.marker([center.lat, center.lon], {
              icon: numberedIcon(brgy, number),
              keyboard: true
            }).bindPopup(popupContent(brgy, number), {
              maxWidth: 280,
              closeButton: true,
              autoPan: true
            });
            markerGroup.addLayer(marker);
            markersByNumber[number] = marker;
            count++;
          });
          if (!count) throw new Error('No numbered South Caloocan boundaries found');
          addFallbackMarkers();
          leafletMap.fitBounds(markerGroup.getBounds().pad(0.1));
        });
    }

    function itemNames(items) {
      return (items || []).map(function (item) {
        return escapeHtml(item.name) + ' (' + escapeHtml(item.quantity) + ')';
      }).join(', ');
    }

    function formatDate(value) {
      if (!value) return '';
      var d = new Date(String(value).replace(' ', 'T'));
      if (isNaN(d.getTime())) return escapeHtml(value);
      return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function popupContent(brgy, number) {
      var level = statusClass(brgy);
      var areaName = brgy.district || districtFor(number) || 'South Caloocan';
      var html =
        '<div class="sc-popup">' +
          '<h3>Barangay ' + escapeHtml(number) + '</h3>' +
          '<p class="sc-popup-area">' + escapeHtml(areaName) + '</p>' +
          '<span class="sc-popup-status ' + level + '">' + escapeHtml(statusText(brgy)) + '</span>';

      if (!brgy.has_report) {
        return html + '<p class="sc-popup-foot">This barangay has not submitted an equipment report yet.</p></div>';
      }

      if (brgy.no_supplies) {
        html += '<p class="sc-popup-alert warning">A report was sent, but no medical supply quantities were filled in.</p>';
      } else if (brgy.incomplete) {
        html += '<p class="sc-popup-alert warning">Some medical supplies were left blank, so this barangay can\'t be rated "All good" yet.</p>';
      }
      // 0 = "Out of stock"; 1-5 = "Low stock" (blank fields are "not reported" and never listed here).
      var outItems = (brgy.low_items || []).filter(function (i) { return Number(i.quantity) === 0; });
      var lowItems = (brgy.low_items || []).filter(function (i) { return Number(i.quantity) > 0; });
      if (outItems.length) {
        html += '<p class="sc-popup-alert critical"><b>Out of stock:</b> ' + outItems.map(function (i) { return escapeHtml(i.name); }).join(', ') + '</p>';
      }
      if (lowItems.length) {
        html += '<p class="sc-popup-alert critical"><b>Low stock:</b> ' + itemNames(lowItems) + '</p>';
      }
      if (brgy.warning_items && brgy.warning_items.length) {
        html += '<p class="sc-popup-alert warning"><b>Warning:</b> ' + itemNames(brgy.warning_items) + '</p>';
      }

      var rows = [
        ['Facemask', 'facemask'], ['Elastic Bandage', 'elastic_bandage'], ['Betadine', 'betadine_dropper'],
        ['Surgical Tape', 'surgical_tape'], ['Gauze Pad', 'gauze_pad'], ['Nasal Cannula', 'nasal_cannula'],
        ['Oxygen Mask', 'oxygen_mask']
      ];
      html += '<table>';
      rows.forEach(function (row) {
        var qty = supplyValue(brgy, row[1]);
        var cls = typeof qty === 'number' ? (qty <= 5 ? 'qty-critical' : (qty <= 15 ? 'qty-warning' : '')) : '';
        var shown = qty === 0 ? 'Out of stock' : (qty === '—' ? 'Not reported' : qty);
        html += '<tr><td>' + row[0] + '</td><td class="' + cls + '">' + escapeHtml(shown) + '</td></tr>';
      });
      html += '</table>';

      html += '<p class="sc-popup-foot">Reported by ' + escapeHtml(brgy.reporter || 'Unknown') +
        (brgy.submitted_at ? ' &middot; ' + formatDate(brgy.submitted_at) : '') + '</p>';
      return html + '</div>';
    }

    function numberedIcon(brgy, number) {
      var level = statusClass(brgy);
      return L.divIcon({
        className: 'sc-leaflet-badge',
        html: '<span class="sc-brgy-badge ' + level + '">' + escapeHtml(String(number)) + '</span>',
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -16]
      });
    }

    function initLeaflet() {
      if (leafletMap) {
        leafletMap.invalidateSize();
        return;
      }

      var southWest = L.latLng(14.6180, 120.9480);
      var northEast = L.latLng(14.6900, 121.0320);
      var bounds = L.latLngBounds(southWest, northEast);

      leafletMap = L.map('map', {
        center: [14.6507, 120.9830],
        zoom: 14,
        minZoom: 12,
        maxZoom: 18,
        maxBounds: bounds,
        maxBoundsViscosity: 1.0,
        dragging: true,
        scrollWheelZoom: true,
        doubleClickZoom: true,
        boxZoom: true,
        keyboard: true,
        zoomControl: true
      });
      leafletMap.setMaxBounds(bounds);

      // Esri Light Gray Canvas: plain, muted basemap so the status circles stand out. No API key needed
      // (CARTO basemaps now require one). Street labels come from a separate, subtle reference layer.
      var esriGray = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_';
      L.tileLayer(esriGray + 'Base/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 18,
        maxNativeZoom: 16,
        attribution: 'Tiles &copy; <a href="https://www.esri.com/">Esri</a> &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors'
      }).addTo(leafletMap);
      L.tileLayer(esriGray + 'Reference/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 18,
        maxNativeZoom: 16,
        opacity: 0.7
      }).addTo(leafletMap);

      markerGroup = L.featureGroup().addTo(leafletMap);

      // Shrink the circles when zoomed out so dense areas (e.g. Grace Park) don't pile up.
      function syncBadgeSize() {
        var z = leafletMap.getZoom();
        mapEl.classList.toggle('sc-zoom-far', z <= 14);
        mapEl.classList.toggle('sc-zoom-mid', z === 15);
      }
      leafletMap.on('zoomend', syncBadgeSize);
      syncBadgeSize();

      loadBoundaryMarkers().catch(function (error) {
        console.warn('Local barangay boundaries unavailable, trying OpenStreetMap:', error);
        return loadNumberedBarangayMarkers();
      }).catch(function (error) {
        console.error('Unable to load numbered South Caloocan boundaries:', error);
        addFallbackMarkers();
        if (markerGroup.getLayers().length) {
          leafletMap.fitBounds(markerGroup.getBounds().pad(0.1));
        }
      });

      var legend = L.control({ position: 'bottomleft' });
      legend.onAdd = function () {
        var div = L.DomUtil.create('div', 'sc-map-legend');
        div.innerHTML =
          '<strong>STATUS LEGEND</strong>' +
          '<span><i class="live-map-dot critical"></i> Out of stock / Low stock</span>' +
          '<span><i class="live-map-dot warning"></i> Warning</span>' +
          '<span><i class="live-map-dot optimal"></i> All good</span>' +
          '<span><i class="live-map-dot idle"></i> No report submitted</span>';
        return div;
      };
      legend.addTo(leafletMap);

      startGpsTracking();
      setTimeout(function () {
        leafletMap.invalidateSize();
        if (!gpsFollow && markerGroup && markerGroup.getLayers().length) {
          leafletMap.fitBounds(markerGroup.getBounds().pad(0.1));
        }
      }, 250);
    }

    // ---- Live GPS "radar" -------------------------------------------------------------
    // Pulsing radar marker that glides to each new position, a "My location" button that
    // follows you, a live status chip, and which barangay you are currently in.
    var gpsFollow = false;
    var gpsLast = null;          // { latlng, accuracy, time }
    var gpsAnim = null;
    var gpsStatusEl = null;
    var gpsBtn = null;

    function barangayAt(latlng) {
      var pt = [latlng[1], latlng[0]];
      function inRing(ring) {
        var inside = false;
        for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
          var xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1];
          if ((yi > pt[1]) !== (yj > pt[1]) && pt[0] < (xj - xi) * (pt[1] - yi) / (yj - yi) + xi) inside = !inside;
        }
        return inside;
      }
      for (var f = 0; f < boundaryFeatures.length; f++) {
        var polys = boundaryFeatures[f].geometry.coordinates;
        for (var p = 0; p < polys.length; p++) {
          if (!inRing(polys[p][0])) continue;
          var inHole = false;
          for (var h = 1; h < polys[p].length; h++) if (inRing(polys[p][h])) inHole = true;
          if (!inHole) return boundaryFeatures[f].properties.number;
        }
      }
      return null;
    }

    function gpsPopupHtml() {
      if (!gpsLast) return '';
      var n = barangayAt(gpsLast.latlng);
      var brgy = n ? byNumber[n] : null;
      return '<div class="sc-popup">' +
        '<h3>You are here</h3>' +
        '<p class="sc-popup-area">' + (n ? 'Inside <b>Barangay ' + n + '</b>' : 'Outside South Caloocan barangays') + '</p>' +
        (n ? '<span class="sc-popup-status ' + statusClass(brgy) + '">' + escapeHtml(statusText(brgy)) + '</span>' : '') +
        '<p class="sc-popup-foot">Accuracy &plusmn;' + Math.round(gpsLast.accuracy) + ' m &middot; ' +
          gpsLast.latlng[0].toFixed(5) + ', ' + gpsLast.latlng[1].toFixed(5) + '</p></div>';
    }

    function setGpsStatus(state, text) {
      if (!gpsStatusEl) return;
      gpsStatusEl.className = 'sc-gps-status ' + state;
      gpsStatusEl.innerHTML = '<i></i><span>' + text + '</span>';
    }

    function refreshGpsStatus() {
      if (!gpsLast) return;
      var secs = Math.max(0, Math.round((Date.now() - gpsLast.time) / 1000));
      var n = barangayAt(gpsLast.latlng);
      setGpsStatus(secs > 30 ? 'stale' : 'live',
        (secs > 30 ? 'GPS signal weak' : 'LIVE GPS') +
        ' &middot; &plusmn;' + Math.round(gpsLast.accuracy) + ' m' +
        (n ? ' &middot; Brgy ' + n : '') +
        ' &middot; ' + (secs < 3 ? 'just now' : secs + 's ago'));
    }

    function glideTo(target) {
      if (gpsAnim) cancelAnimationFrame(gpsAnim);
      var from = gpsMarker.getLatLng(), start = null, dur = 900;
      function step(ts) {
        if (start === null) start = ts;
        var t = Math.min(1, (ts - start) / dur), e = t * (2 - t);
        var ll = [from.lat + (target[0] - from.lat) * e, from.lng + (target[1] - from.lng) * e];
        gpsMarker.setLatLng(ll);
        if (t < 1) gpsAnim = requestAnimationFrame(step);
      }
      gpsAnim = requestAnimationFrame(step);
    }

    function setFollow(on) {
      gpsFollow = on;
      if (gpsBtn) gpsBtn.classList.toggle('following', on);
      // setView (not flyTo) so the next GPS update can't cut the zoom off halfway.
      if (on && gpsLast) leafletMap.setView(gpsLast.latlng, Math.max(leafletMap.getZoom(), 17), { animate: true });
    }

    function addGpsControls() {
      var ctl = L.control({ position: 'topleft' });
      ctl.onAdd = function () {
        var wrap = L.DomUtil.create('div', 'leaflet-bar sc-gps-control');
        gpsBtn = L.DomUtil.create('a', 'sc-gps-btn', wrap);
        gpsBtn.href = '#';
        gpsBtn.title = 'Show and follow my location';
        gpsBtn.setAttribute('role', 'button');
        gpsBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /><circle cx="12" cy="12" r="7" fill="none"/></svg>';
        L.DomEvent.disableClickPropagation(wrap);
        L.DomEvent.on(gpsBtn, 'click', function (ev) {
          L.DomEvent.preventDefault(ev);
          if (!gpsLast) { setGpsStatus('searching', 'Searching for GPS signal&hellip;'); gpsFollow = true; gpsBtn.classList.add('following'); return; }
          setFollow(!gpsFollow);
        });
        return wrap;
      };
      ctl.addTo(leafletMap);

      var status = L.control({ position: 'topright' });
      status.onAdd = function () {
        gpsStatusEl = L.DomUtil.create('div', 'sc-gps-status searching');
        return gpsStatusEl;
      };
      status.addTo(leafletMap);

      // Dragging the map yourself stops "follow me" until the button is pressed again.
      leafletMap.on('dragstart', function () { if (gpsFollow) setFollow(false); });
    }

    // ---- "You are here" pin with the logged-in person's profile picture (or initials) --------------------
    var me = window.SC_ME || { name: '', photo: '' };
    function meEsc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function meInitials(name) {
      var parts = String(name || '').trim().split(/[\s,]+/).filter(Boolean);
      if (!parts.length) return '?';
      return (parts[0].charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }
    // Only local uploads / app images are used as the photo (never an outside URL).
    function mePhotoOk(p) { return typeof p === 'string' && /^(uploads|assets)\/[\w\-.\/]+\.(jpe?g|png|gif|webp)$/i.test(p) && p.indexOf('..') === -1; }
    function meIcon() {
      var initials = '<span class="sc-me-initials">' + meEsc(meInitials(me.name)) + '</span>';
      var face = mePhotoOk(me.photo)
        // If the picture can't load (deleted file, etc.) it falls back to the initials.
        ? '<img src="' + meEsc(me.photo) + '" alt="" onerror="this.parentNode.classList.add(\'no-photo\');this.remove();">' + initials
        : initials;
      return L.divIcon({
        className: 'sc-me-pin-icon',
        // Animated: ripple on the ground at the tip, the pin drops in once, then floats gently.
        html: '<span class="sc-me-pulse"></span><span class="sc-me-pulse p2"></span>' +
              '<div class="sc-me-drop"><div class="sc-me-bob">' +
                '<div class="sc-me-pin' + (mePhotoOk(me.photo) ? '' : ' no-photo') + '" title="' + meEsc(me.name || 'You') + '"><div class="sc-me-face">' + face + '</div></div>' +
              '</div></div>',
        iconSize: [48, 56],
        iconAnchor: [24, 52],   // the pin's tip (40px teardrop turned 45°, centered at 24,24 → tip at 24,52)
        popupAnchor: [0, -50]
      });
    }
    // Picks up a new profile picture (or name) without reloading: every minute and whenever the tab is reopened.
    function refreshMe() {
      if (document.hidden) return;
      fetch('my_avatar.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || (d.photo === me.photo && d.name === me.name)) return;
          me = { name: d.name || '', photo: d.photo || '' };
          if (gpsMarker) gpsMarker.setIcon(meIcon());
        })
        .catch(function () {});
    }
    setInterval(refreshMe, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshMe(); });

    function startGpsTracking() {
      addGpsControls();
      if (!navigator.geolocation) { setGpsStatus('error', 'GPS not supported on this browser'); return; }
      if (!window.isSecureContext) { setGpsStatus('error', 'GPS needs https:// or localhost'); return; }
      setGpsStatus('searching', 'Searching for GPS signal&hellip;');

      gpsWatchId = navigator.geolocation.watchPosition(
        function (pos) {
          var latlng = [pos.coords.latitude, pos.coords.longitude];
          var accuracy = pos.coords.accuracy || 40;
          var heading = pos.coords.heading;
          gpsLast = { latlng: latlng, accuracy: accuracy, time: Date.now() };

          if (!gpsMarker) {
            // "You are here" pin with the person's profile picture; its tip is their location.
            gpsMarker = L.marker(latlng, {
              zIndexOffset: 2000,
              interactive: true,
              icon: meIcon()
            }).bindPopup(gpsPopupHtml, { maxWidth: 260 }).addTo(leafletMap);
            didCenterOnGps = true;
            if (gpsFollow) setFollow(true);
          } else {
            glideTo(latlng);
            if (gpsFollow) leafletMap.panTo(latlng, { animate: true, duration: 0.8 });
          }
          if (gpsMarker.isPopupOpen()) gpsMarker.setPopupContent(gpsPopupHtml());
          refreshGpsStatus();
        },
        function (err) {
          if (err.code === 1) setGpsStatus('error', 'Location permission denied &mdash; allow it in the browser');
          else if (err.code === 3 && !gpsLast) setGpsStatus('searching', 'Still searching for GPS signal&hellip;');
          else if (!gpsLast) setGpsStatus('error', 'Location unavailable');
        },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 }
      );
      setInterval(refreshGpsStatus, 1000);
    }

    function buildList() {
      if (!listPane) return;
      var board = document.createElement('div');
      board.className = 'sc-map-board';
      districtNames.forEach(function (name) {
        var info = districts[name];
        var panel = document.createElement('section');
        panel.className = 'sc-district';
        panel.style.setProperty('--district-color', (info && info.color) || '#95a5a6');
        var title = document.createElement('h4');
        title.textContent = name;
        panel.appendChild(title);
        var grid = document.createElement('div');
        grid.className = 'sc-brgy-grid';
        ((info && info.barangays) || []).forEach(function (num) {
          var brgy = byNumber[num] || { number: num, label: 'Barangay ' + num, level: 'idle' };
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'sc-brgy-cell ' + statusClass(brgy);
          btn.dataset.barangay = String(brgy.number);
          btn.textContent = String(brgy.number);
          btn.title = brgy.label || ('Barangay ' + brgy.number);
          btn.addEventListener('click', function () {
            focusBarangay(brgy.number);
          });
          grid.appendChild(btn);
        });
        panel.appendChild(grid);
        board.appendChild(panel);
      });
      listPane.innerHTML = '';
      listPane.appendChild(board);
    }

    function focusBarangay(number) {
      if (mapPane) mapPane.hidden = false;
      if (listPane) listPane.hidden = true;
      document.querySelectorAll('[data-map-view]').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-map-view') === 'map');
      });
      initLeaflet();
      var marker = markersByNumber[number];
      if (marker) leafletMap.setView(marker.getLatLng(), 16);
      if (marker) marker.openPopup();
      document.querySelectorAll('.live-alert-card').forEach(function (card) {
        card.classList.toggle('active', parseInt(card.getAttribute('data-barangay'), 10) === number);
      });
    }

    buildList();
    initLeaflet();

    document.querySelectorAll('[data-map-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-map-view');
        document.querySelectorAll('[data-map-view]').forEach(function (b) {
          b.classList.toggle('active', b === btn);
        });
        if (mapPane) mapPane.hidden = view !== 'map';
        if (listPane) listPane.hidden = view !== 'list';
        if (view === 'map') {
          setTimeout(function () {
            if (leafletMap) {
              leafletMap.invalidateSize();
              if (markerGroup && markerGroup.getLayers().length) {
                leafletMap.fitBounds(markerGroup.getBounds().pad(0.1));
              }
            }
          }, 50);
        }
      });
    });

    document.querySelectorAll('[data-focus-barangay]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        focusBarangay(parseInt(btn.getAttribute('data-focus-barangay'), 10));
      });
    });

    window.addEventListener('beforeunload', function () {
      if (gpsWatchId != null && navigator.geolocation) {
        navigator.geolocation.clearWatch(gpsWatchId);
      }
    });
  });
})();
