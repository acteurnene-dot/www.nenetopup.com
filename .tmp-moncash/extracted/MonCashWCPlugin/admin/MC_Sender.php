<!DOCTYPE html>
<html>
    <head>
        <title>MC_Sender</title>
    </head>
    <body>
        <form action="http://localhost:8080/Checkout/Rest/<?php echo $_GET['business_key']?>" >
            <input type="hidden" name="orderId" value="<?php echo $_GET['order_id']?>">
            <input type="hidden" name="amount" value="<?php echo $_GET['amount']?>">
            <input type="submit" id="SendData" value="send_MC" />
        </form>

    <script type="text/javascript" src="<?php echo '../includes/jquery.js' ;?>">
    </script>
    <script type="text/javascript">
        $(document).ready(function(){
            alert("OK");
        });

    </script>
    </body>
</html>