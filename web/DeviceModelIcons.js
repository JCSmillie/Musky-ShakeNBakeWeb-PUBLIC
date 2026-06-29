(function (global) {
  const IPAD_MODEL_MAP = {
    'ipad6,11': 5, 'ipad6,12': 5,
    'ipad7,5': 6, 'ipad7,6': 6,
    'ipad7,11': 7, 'ipad7,12': 7,
    'ipad11,6': 8, 'ipad11,7': 8,
    'ipad12,1': 9, 'ipad12,2': 9,
    'ipad13,18': 10, 'ipad13,19': 10,
    'ipad14,10': 11, 'ipad14,11': 11,
    'ipad15,7': 11,
    'ipad16,5': 12
  };

  const MACBOOK_AIR_IDS = new Set(['mac14,2', 'mac16,12', 'macbookair10,1']);
  const MACBOOK_NEO_IDS = new Set(['mac17,5']);
  const DESKTOP_MAC_IDS = new Set([
    'imac13,3', 'imac14,3', 'imac18,2', 'imac21,1', 'imac21,2',
    'mac15,4', 'mac16,2', 'mac16,3'
  ]);

  function normalize(value) {
    return String(value || '').trim();
  }

  function normalizeCode(value) {
    return normalize(value).toLowerCase().replace(/\s+/g, '');
  }

  function joinPath(base, filename) {
    return String(base || '../icons').replace(/\/+$/, '') + '/' + filename;
  }

  function ordinal(number) {
    const abs = Math.abs(number);
    const mod100 = abs % 100;
    if (mod100 >= 11 && mod100 <= 13) {
      return `${number}th`;
    }

    switch (abs % 10) {
      case 1: return `${number}st`;
      case 2: return `${number}nd`;
      case 3: return `${number}rd`;
      default: return `${number}th`;
    }
  }

  function extractIpadGeneration(searchText) {
    const match = searchText.match(/ipad\s*\(?\s*([0-9]{1,2})(?:st|nd|rd|th)?/i);
    return match ? Number(match[1]) : null;
  }

  function laptopLabel(searchText, deviceModel, modelName) {
    if (searchText.includes('macbook neo')) return 'MacBook Neo';
    if (searchText.includes('macbook air') || searchText.includes('macbookair')) return 'MacBook Air';
    if (searchText.includes('macbook pro') || searchText.includes('macbookpro')) return 'MacBook Pro';
    if (searchText.includes('macbook')) return 'MacBook';
    if (searchText.includes('laptop') || searchText.includes('notebook')) return 'Laptop';
    return modelName || deviceModel || 'Laptop';
  }

  function resolveDeviceIconMeta(input) {
    const iconBase = input && input.iconBase ? input.iconBase : '../icons';
    const deviceModel = normalize(input && input.deviceModel);
    const modelName = normalize(input && input.modelName);
    const deviceType = normalize(input && input.deviceType);
    const modelID = deviceModel || modelName;
    const modelCode = normalizeCode(deviceModel);
    const modelNameCode = normalizeCode(modelName);
    const searchText = `${deviceModel} ${modelName} ${deviceType}`.toLowerCase().trim();

    let generation = IPAD_MODEL_MAP[modelCode] ?? IPAD_MODEL_MAP[modelNameCode] ?? null;
    if (generation === null) {
      generation = extractIpadGeneration(searchText);
    }

    if (Number.isFinite(generation)) {
      return {
        icon: joinPath(iconBase, generation <= 9 ? 'ipad_home.png' : 'ipad_flat.png'),
        label: `${ordinal(generation)} Gen`,
        modelID
      };
    }

    if (/ipad1[0-9]/i.test(searchText)) {
      return {
        icon: joinPath(iconBase, 'ipad_flat.png'),
        label: '11th Gen',
        modelID
      };
    }

    if (MACBOOK_NEO_IDS.has(modelCode) || searchText.includes('macbook neo')) {
      return {
        icon: joinPath(iconBase, 'MuskyMacBookNeo.png'),
        label: 'MacBook Neo',
        modelID
      };
    }

    if (MACBOOK_AIR_IDS.has(modelCode) || searchText.includes('macbook air') || searchText.includes('macbookair')) {
      return {
        icon: joinPath(iconBase, 'MuskyMacBookAir.png'),
        label: 'MacBook Air',
        modelID
      };
    }

    const looksDesktop = DESKTOP_MAC_IDS.has(modelCode)
      || searchText.includes('imac')
      || searchText.includes('mac mini')
      || searchText.includes('macmini')
      || searchText.includes('desktop');
    const looksAppleLaptop = !looksDesktop && (
      searchText.includes('macbook')
      || searchText.includes('laptop')
      || searchText.includes('notebook')
    );

    if (looksAppleLaptop) {
      return {
        icon: joinPath(iconBase, 'FakeMac.png'),
        label: laptopLabel(searchText, deviceModel, modelName),
        modelID
      };
    }

    if (deviceModel || modelName || deviceType) {
      return {
        icon: joinPath(iconBase, 'not_ipad.png'),
        label: modelName || deviceModel || 'Unsupported',
        modelID
      };
    }

    return {
      icon: joinPath(iconBase, 'default.png'),
      label: 'Unsupported',
      modelID
    };
  }

  global.muskyDeviceIconMeta = resolveDeviceIconMeta;
})(window);
