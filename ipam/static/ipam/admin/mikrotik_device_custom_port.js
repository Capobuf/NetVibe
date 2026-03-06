(function () {
  "use strict";

  function defaultPortForScheme(schemeValue) {
    return schemeValue === "http" ? "80" : "443";
  }

  function isChecked(input) {
    return Boolean(input && input.checked);
  }

  function findPortRow(portInput) {
    if (!portInput) {
      return null;
    }

    var label = document.querySelector('label[for="' + portInput.id + '"]');
    var fromInput = portInput.closest(".form-row, .field-port, [data-field-name='port']");
    if (fromInput) {
      return fromInput;
    }
    if (label) {
      return label.closest(".form-row, .field-port, [data-field-name='port']") || label.parentElement;
    }
    return portInput.parentElement;
  }

  function applyPortVisibility() {
    var schemeInput = document.querySelector('[name="scheme"]');
    var customPortInput = document.querySelector('[name="custom_port"]');
    var portInput = document.querySelector('[name="port"]');

    if (!schemeInput || !customPortInput || !portInput) {
      return;
    }

    var portRow = findPortRow(portInput);
    var customEnabled = isChecked(customPortInput);

    if (!customEnabled) {
      portInput.value = defaultPortForScheme(schemeInput.value);
    }

    if (portRow) {
      portRow.style.display = customEnabled ? "" : "none";
    } else {
      portInput.style.display = customEnabled ? "" : "none";
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var schemeInput = document.querySelector('[name="scheme"]');
    var customPortInput = document.querySelector('[name="custom_port"]');

    if (!schemeInput || !customPortInput) {
      return;
    }

    applyPortVisibility();
    schemeInput.addEventListener("change", applyPortVisibility);
    customPortInput.addEventListener("change", applyPortVisibility);
  });
})();
