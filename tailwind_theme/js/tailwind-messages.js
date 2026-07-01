(function (Drupal) {
  'use strict';

  Drupal.behaviors.tailwindMessages = {
    attach: function () {
      const messageWrapper = document.querySelector('div[data-tailwind-messages]');
      if (messageWrapper !== null && messageWrapper !== undefined && messageWrapper.innerHTML.trim().length > 0) {
        const messageTypeWrappers = messageWrapper.querySelectorAll('div[data-tailwind-message]');

        if (messageTypeWrappers !== null && messageTypeWrappers !== undefined && messageTypeWrappers.length > 0) {
          messageTypeWrappers.forEach(messageTypeWrapper => {
            const messageTypeTriggerClose = messageTypeWrapper.querySelector('button[data-tailwind-message-close]');

            if (messageTypeTriggerClose !== null && messageTypeTriggerClose !== undefined) {
              messageTypeTriggerClose.addEventListener('click', function () {
                messageTypeWrapper.remove();
              });
            }
          });
        }
      }
    }
  };
})(Drupal);
