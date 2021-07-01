import requests
import time
import json
import os
import qrcode
from PIL import Image
import urllib.parse as urlparse

logconfig = {}


def getLoginURL():
    try:
        temp = requests.get(url='https://passport.bilibili.com/qrcode/getLoginUrl')
        temp = json.loads(temp.text)
        if temp['code'] != 0:
            return False
        return temp
    except(BaseException):
        return False


def checkConfigFile():
    filename = './config.txt'
    if os.path.exists(filename):
        if os.path.getsize(filename) != 0:
            f = open(file=filename, mode="r", encoding="utf-8")
            temp = f.read().replace("\r", "").replace("\n", "")
            f.close()
            return temp
        else:
            return False
    else:
        return False


def showQRCode(url):
    qr = qrcode.make(data=url)
    with open('qrcode.png', 'wb') as f:
        qr.save(f)
    img = Image.open('qrcode.png')
    img.show()
    return


def checkLogin(create_time, key):
    global logconfig
    time_local = time.localtime(int(create_time) + 180)
    time_format = time.strftime("%H:%M:%S", time_local)
    print('请打开哔哩哔哩APP扫描弹出的二维码，注意二维码将在 {time} 过期 \n'.format(time = time_format))
    temp = str(input('在手机上确认登录后输入 OK 以继续：\n').format(time_format))
    if(temp == 'ok'):
        result = postReq(key)
    elif(temp == 'OK'):
        result = postReq(key)
    else:
        print('输入了其他字符，正在退出。\n')
        exit(0)
    #  print(result)
    writeConfig(result)
    logconfig = json.loads(result)



def postReq(authKey):
    try:
        form = [
            ('oauthKey', authKey),
        ]
        temp = requests.post(url='http://passport.bilibili.com/qrcode/getLoginInfo', data=form)
        return temp.text
    except(BaseException):
        return False


def writeConfig(content):
    filename = "./config.txt"
    with open(file=filename, mode="w", encoding="utf-8") as f:
        f.write(content)
        f.close()
    return


def getCoinNum(sessdict):                                           
    #  获取硬币数同时检验能否成功登录
    cookies={
        'SESSDATA': sessdict['data'],
        'DedeUserID': ''
    } 



if __name__ == '__main__':
    logstatus = checkConfigFile()
    if logstatus == False:                                 #  扫 码 登 录
        content = getLoginURL()
        if content == False:
            print('申请二维码失败，已退出。\n')
            exit(0)
        showQRCode(content['data']['url'])
        checkLogin(content['ts'], content['data']['oauthKey'])
    else:                                                  #  本地配置登录
        logconfig = json.loads(logstatus)
        print("检测到配置文件，复制config.txt文件到index.php文件下即可。")
          



    
    