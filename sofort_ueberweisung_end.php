<?php
/**
 * @author    Gregor Kralik
 * @copyright 2016 Gregor Kralik
 * @license   GNU LGPL http://www.gnu.org/licenses/lgpl.html
 */
defined('_JEXEC') or die('Restricted access');
?><div class="hikashop_sofort_end" id="hikashop_sofort_end">
	<span id="hikashop_sofort_end_message" class="hikashop_sofort_end_message">
		<?php echo JText::sprintf('PLEASE_WAIT_BEFORE_REDIRECTION_TO_X', $this->payment_name).'<br/>'. JText::_('CLICK_ON_BUTTON_IF_NOT_REDIRECTED');?>
	</span>
	<span id="hikashop_sofort_end_spinner" class="hikashop_sofort_end_spinner hikashop_checkout_end_spinner">
	</span>
	<br/>
	<form id="hikashop_sofort_form" name="hikashop_sofort_form" action="<?php echo $this->redirect_url;?>" method="post">
		<div id="hikashop_sofort_end_image" class="hikashop_sofort_end_image">
			<input id="hikashop_sofort_button" type="submit" class="btn btn-primary" value="<?php echo JText::_('PAY_NOW');?>" name="" alt="<?php echo JText::_('PAY_NOW');?>" />
		</div>
<?php
JRequest::setVar('noform',1);
?>
	</form>
	<script type="text/javascript">
		<!--
		document.getElementById('hikashop_sofort_form').submit();
		//-->
	</script>
</div>
