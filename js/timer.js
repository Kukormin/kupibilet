function timer()
{
    var my_timer = $('#reserve_timer');
	if (!my_timer.length)
		return;

    var time = my_timer.text();
    var arr = time.split(":");
    var h = arr[0];
    var m = arr[1];
    var s = arr[2];
    if (s == 0) {
        if (m == 0) {
            if (h == 0) {
                alert("Время вышло");
                window.location.reload();
                return;
            }
            h--;
            m = 60;
            if (h < 10) h = "0" + h;
        }
        m--;
        if (m < 10) m = "0" + m;
        s = 59;
    }
    else s--;
    if (s < 10) s = "0" + s;
    document.getElementById("reserve_timer").innerHTML = h+":"+m+":"+s;
    setTimeout(timer, 1000);
}