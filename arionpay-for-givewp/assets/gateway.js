/* ArionPay for GiveWP: shows the ArionPay option in visual form builder (v3) forms. No build step needed. */
(function () {
  var gateway = {
    id: 'arionpay',
    Fields: function () {
      var message = (gateway.settings && gateway.settings.message) || '';
      return window.React.createElement('p', { className: 'arionpay-givewp-message' }, message);
    }
  };
  if (window.givewp && window.givewp.gateways) {
    window.givewp.gateways.register(gateway);
  }
})();
