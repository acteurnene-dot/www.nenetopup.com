(function ($) {
    'use strict';

    function TopupVerifier($wrap) {
        this.$wrap = $wrap;
        this.$form = $wrap.closest('form.cart');
        this.$input = $wrap.find('#giftaccess_topup_player_id');
        this.$result = $wrap.find('.giftaccess-topup-verify-result');
        this.$verified = $wrap.find('#giftaccess_topup_player_verified');
        this.$playerName = $wrap.find('#giftaccess_topup_player_name');
        this.$cartButton = this.$form.find('.single_add_to_cart_button');
        this.lastVerifiedId = '';
        this.verifyTimer = null;
        this.currentRequest = null;
        this.verifyRequired = window.GiftAccessTopupProduct && window.GiftAccessTopupProduct.verifyRequired === 'yes';
        this.texts = window.GiftAccessTopupProduct ? window.GiftAccessTopupProduct.texts : {};

        this.bind();
        this.reset();
        this.scheduleVerify();
    }

    TopupVerifier.prototype.bind = function () {
        var self = this;

        this.$input.on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                self.verify();
            }
        });

        this.$input.on('input', function () {
            if ($.trim(self.$input.val()) !== self.lastVerifiedId) {
                self.reset();
                self.scheduleVerify();
            }
        });

        this.$form.on('found_variation', function () {
            self.reset();
            self.scheduleVerify();
        });

        this.$form.on('reset_data hide_variation', function () {
            self.reset();
        });

        this.$form.on('submit', function (event) {
            if (self.verifyRequired && self.$verified.val() !== 'yes') {
                event.preventDefault();
                self.renderError(self.texts.required || 'Vérification ID requise.');
            }
        });
    };

    TopupVerifier.prototype.productId = function () {
        return this.$form.find('[name="add-to-cart"]').val() || this.$form.find('[name="product_id"]').val() || 0;
    };

    TopupVerifier.prototype.variationId = function () {
        return this.$form.find('input[name="variation_id"]').val() || 0;
    };

    TopupVerifier.prototype.setState = function (state) {
        this.$wrap
            .removeClass('is-idle is-loading is-verified is-error')
            .addClass('is-' + state);
    };

    TopupVerifier.prototype.reset = function () {
        clearTimeout(this.verifyTimer);
        this.$verified.val('no');
        this.$playerName.val('');
        this.$result.empty().removeClass('is-success is-error is-loading');
        this.setState('idle');

        if (this.currentRequest) {
            this.currentRequest.abort();
            this.currentRequest = null;
        }

        if (this.verifyRequired) {
            this.$cartButton.prop('disabled', true).addClass('disabled');
        }
    };

    TopupVerifier.prototype.scheduleVerify = function () {
        var self = this;
        var playerId = $.trim(this.$input.val());

        clearTimeout(this.verifyTimer);
        if (!playerId || playerId === this.lastVerifiedId) {
            return;
        }

        this.verifyTimer = setTimeout(function () {
            self.verify();
        }, 700);
    };

    TopupVerifier.prototype.verify = function () {
        var self = this;
        var playerId = $.trim(this.$input.val());
        var productId = this.productId();
        var variationId = this.variationId();

        clearTimeout(this.verifyTimer);

        if (!playerId) {
            this.renderError(this.texts.emptyId || 'Entrez un ID avant de vérifier.');
            return;
        }

        if (this.$form.hasClass('variations_form') && !variationId) {
            return;
        }

        if (playerId === this.lastVerifiedId && this.$verified.val() === 'yes') {
            return;
        }

        if (this.currentRequest) {
            this.currentRequest.abort();
            this.currentRequest = null;
        }

        this.setState('loading');
        this.$result.removeClass('is-success is-error').addClass('is-loading').html(this.statusCard(this.texts.checking || 'Vérification en cours...', '', 'loading'));

        this.currentRequest = $.ajax({
            url: window.GiftAccessTopupProduct.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'giftaccess_topup_verify_player',
                nonce: window.GiftAccessTopupProduct.nonce,
                product_id: productId,
                variation_id: variationId,
                player_id: playerId
            }
        }).done(function (response) {
            if ($.trim(self.$input.val()) !== playerId) {
                return;
            }

            if (response && response.success) {
                self.renderSuccess(response.data || {}, playerId);
                return;
            }

            self.renderError(response && response.data && response.data.message ? response.data.message : (self.texts.failed || 'ID invalide.'));
        }).fail(function (xhr, status) {
            var message = self.texts.serverError || 'Vérification impossible pour le moment.';

            if (status === 'abort') {
                return;
            }

            if ($.trim(self.$input.val()) !== playerId) {
                return;
            }

            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            self.renderError(message);
        }).always(function () {
            self.currentRequest = null;
        });
    };

    TopupVerifier.prototype.renderSuccess = function (data, playerId) {
        var playerName = data.player_name || '';
        var title = this.texts.verified || 'Compte vérifié';
        var detail = '<span><strong>' + this.escape(this.texts.playerId || 'ID joueur') + '</strong> ' + this.escape(data.player_id || playerId) + '</span>';

        if (playerName) {
            detail = '<span><strong>' + this.escape(this.texts.playerName || 'Nom joueur') + '</strong> ' + this.escape(playerName) + '</span>' + detail;
        }

        this.$verified.val('yes');
        this.$playerName.val(playerName);
        this.lastVerifiedId = playerId;
        this.$cartButton.prop('disabled', false).removeClass('disabled');
        this.setState('verified');
        this.$result.removeClass('is-error is-loading').addClass('is-success').html(this.statusCard(title, detail, 'success'));
    };

    TopupVerifier.prototype.renderError = function (message) {
        this.$verified.val('no');
        this.$playerName.val('');
        this.$cartButton.prop('disabled', this.verifyRequired).toggleClass('disabled', this.verifyRequired);
        this.setState('error');
        this.$result.removeClass('is-success is-loading').addClass('is-error').html(this.statusCard(message, '', 'error'));
    };

    TopupVerifier.prototype.statusCard = function (title, detail, type) {
        var html = '<div class="giftaccess-topup-result-card giftaccess-topup-result-' + this.escape(type) + '">';
        html += '<span class="giftaccess-topup-result-icon" aria-hidden="true"></span>';
        html += '<div>';
        html += '<strong>' + this.escape(title) + '</strong>';
        if (detail) {
            html += '<p>' + detail + '</p>';
        }
        html += '</div></div>';

        return html;
    };

    TopupVerifier.prototype.escape = function (value) {
        return $('<div />').text(value || '').html();
    };

    $(function () {
        $('.giftaccess-topup-player-field').each(function () {
            new TopupVerifier($(this));
        });
    });
})(jQuery);
